<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\BusinessAlert;
use App\Models\ConsentEvent;
use App\Models\CustomerNumberHistory;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Services\Attribution\AttributionService;
use App\Services\Referral\EnrollmentService;
use App\Services\Referral\NonceService;
use App\Services\Referral\RedemptionService;
use App\Services\Security\TurnstileVerifier;
use App\Services\Twilio\TwilioSmsGateway;
use App\Support\SmsSegments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Final branch sweep: provider failure pages, cross-business
 * flow reuse, state-guard races resolved by refresh, and the
 * small service branches the HTTP suites do not reach.
 */
class BranchSweepTest extends TestCase
{
    use RefreshDatabase;

    private FakePhoneVerificationGateway $verify;

    private FakeSmsGateway $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verify = new FakePhoneVerificationGateway;
        $this->sms = new FakeSmsGateway;
        $this->app->instance(PhoneVerificationGateway::class, $this->verify);
        $this->app->instance(SmsGateway::class, $this->sms);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    private function carry($response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    public function test_resend_provider_failures_render_their_pages(): void
    {
        // Raise the start budgets so each resend reaches the
        // provider fake; the budget behavior has its own tests.
        config(['referrals.verify.starts_per_phone_per_hour' => 50]);
        config(['referrals.verify.starts_per_ip_per_hour' => 50]);
        config(['referrals.verify.starts_per_business_per_hour' => 500]);

        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $this->carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertRedirect(route('refer.verify')));

        $this->verify->startOutcomes['+12125550111'] = 'provider_error';
        $this->travel(61)->seconds();
        $this->post(route('refer.verify.resend'))->assertOk()->assertSee('Something went wrong on our end');

        $this->verify->startOutcomes['+12125550111'] = 'rate_limited';
        $this->travel(61)->seconds();
        $this->post(route('refer.verify.resend'))->assertOk()->assertSee('Too many tries');

        // An outcome the client does not know still lands on the
        // designed provider-error state, never a blank page.
        $this->verify->startOutcomes['+12125550111'] = 'something_unmapped';
        $this->travel(61)->seconds();
        $this->post(route('refer.verify.resend'))->assertOk()->assertSee('Something went wrong on our end');
    }

    public function test_verify_endpoints_without_a_flow_go_home(): void
    {
        $this->get(route('refer.verify'))->assertRedirect(route('home'));
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('home'));
        $this->post(route('refer.verify.resend'))->assertRedirect(route('home'));
    }

    public function test_friend_page_with_an_unknown_share_link_is_the_bad_link_page(): void
    {
        $this->get(route('refer.friend', ['token' => str_repeat('u', 43)]))
            ->assertNotFound()
            ->assertSee('Something is not right with that link');
    }

    public function test_friend_claim_rejects_a_non_nanp_number(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [, $shareToken] = World::membership($business, $referrer);

        $this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '1234567890',
        ])->assertSessionHasErrors('phone');
    }

    public function test_one_flow_can_switch_businesses_between_scans(): void
    {
        $alpha = World::business(['name' => 'Alpha Cuts']);
        [, $alphaToken] = World::qr($alpha);
        $beta = World::business(['name' => 'Beta Brews']);
        [, $betaToken] = World::qr($beta);

        $this->carry($this->get(route('refer.landing', ['token' => $alphaToken]))->assertSee('Alpha Cuts'));

        // Same browser session scans a different shop's code: the
        // flow rebinds instead of trapping the customer in Alpha.
        $this->get(route('refer.landing', ['token' => $betaToken]))
            ->assertOk()
            ->assertSee('Beta Brews')
            ->assertDontSee('Alpha Cuts');

        $this->carry($this->post(route('refer.enroll', ['token' => $betaToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertRedirect(route('refer.verify')));
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share'));
        $this->assertSame($beta->id, Membership::query()->firstOrFail()->business_id);
    }

    public function test_claim_start_blocked_by_the_phone_budget_shows_the_rate_page(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [, $shareToken] = World::membership($business, $referrer);

        $budget = app(\App\Services\Security\RateBudgetService::class);
        $key = $budget->keyFor('verify_phone', hash('sha256', '+12125550111'));
        for ($i = 0; $i < 3; $i++) {
            $budget->hit($key, 3600);
        }

        $this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550111',
        ])->assertOk()->assertSee('Too many tries');
    }

    public function test_enroll_with_a_revoked_foreign_code_gets_no_grace(): void
    {
        $alpha = World::business();
        [, $alphaToken] = World::qr($alpha);
        $beta = World::business(['name' => 'Beta Brews']);
        [$betaQr, $betaToken] = World::qr($beta);
        $betaQr->forceFill(['state' => 'revoked', 'revoked_at' => now()->subHour()])->save();

        $this->carry($this->get(route('refer.landing', ['token' => $alphaToken])));

        // The revocation grace belongs to the flow that scanned
        // the code; a flow bound to another business gets none.
        $this->post(route('refer.enroll', ['token' => $betaToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '2125550111',
        ])->assertNotFound()->assertSee('This code is not active');
    }

    public function test_denied_reward_on_the_staff_phone_is_the_generic_state(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        [, $claimToken] = World::reward($membership, $referral, ['state' => Reward::STATE_DENIED]);

        $this->get(route('redeem.show', ['token' => $claimToken]))
            ->assertNotFound()
            ->assertSee('This code is not active');
    }

    public function test_voided_referral_receipt_is_the_generic_unavailable_state(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [, $redeemToken] = World::referral($membership, $friend, ['state' => Referral::STATE_VOIDED]);

        $this->get(route('refer.redeem.page', ['token' => $redeemToken]))
            ->assertOk()
            ->assertSee('This code is not active');
    }

    public function test_webhook_failed_and_unmapped_statuses(): void
    {
        config(['services.twilio.webhook_auth_token' => 'tok']);
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(20)]);
        [$reward] = World::reward($membership, $referral, ['notification_state' => 'queued']);

        \App\Jobs\SendRewardReadySms::dispatchSync($reward->id);
        $message = \App\Models\SmsMessage::query()->firstOrFail();
        $message->forceFill(['twilio_message_sid' => 'SM999', 'delivery_state' => 'sent'])->save();

        $url = route('webhooks.twilio.status');
        $validator = new \Twilio\Security\RequestValidator('tok');
        $post = function (array $params) use ($validator, $url) {
            $this->call('POST', $url, $params, [], [], [
                'HTTP_X_TWILIO_SIGNATURE' => $validator->computeSignature($url, $params),
            ]);
        };

        $post(['MessageSid' => 'SM999', 'MessageStatus' => 'failed']);
        $this->assertSame('failed', $message->fresh()->delivery_state);

        $post(['MessageSid' => 'SM999', 'MessageStatus' => 'queued']);
        $this->assertSame('failed', $message->fresh()->delivery_state);
    }

    public function test_state_guards_reread_before_deciding(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        // A stale referral instance whose row was redeemed by a
        // parallel request: applyExpiry must refuse.
        [$stale] = World::referral($membership, World::customer('+12125550621', 'Sta', 'Le'), [
            'expires_at' => now()->subHour(),
        ]);
        Referral::query()->whereKey($stale->id)->update(['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        $this->assertFalse($stale->applyExpiry());
        $this->assertSame(Referral::STATE_REDEEMED, $stale->fresh()->state);

        [$referral] = World::referral($membership, World::customer('+12125550622', 'Ves', 'Ted'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(20),
        ]);
        [$staleReward] = World::reward($membership, $referral, [
            'state' => Reward::STATE_PENDING_VESTING, 'available_at' => now()->subMinute(),
        ]);
        Reward::query()->whereKey($staleReward->id)->update(['state' => Reward::STATE_CLAIMED, 'claimed_at' => now()]);
        $this->assertFalse($staleReward->applyVesting());
        $this->assertSame(Reward::STATE_CLAIMED, $staleReward->fresh()->state);
    }

    public function test_unattributed_week_over_week_jump_holds_the_reward(): void
    {
        $business = World::business(['daily_referral_cap' => 100, 'monthly_referral_cap' => 500]);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        // Previous week: ten redemptions, all attributed.
        for ($i = 0; $i < 10; $i++) {
            $event = ReferralEvent::record('redemption', 'owner', $business, null, null, null, [], '127.0.0.1');
            $event->forceFill(['created_at' => now()->subDays(10)])->save();
        }
        // This week: ten redemptions, four at the counter (40
        // percent, under the share threshold, but a 40-point jump).
        for ($i = 0; $i < 6; $i++) {
            ReferralEvent::record('redemption', 'owner', $business, null, null, null, [], '127.0.0.1');
        }
        for ($i = 0; $i < 4; $i++) {
            ReferralEvent::record('redemption', 'counter', $business, null, null, null, [], '127.0.0.1');
        }

        [$pending] = World::referral($membership, World::customer('+12125550623', 'New', 'Friend'));
        $result = app(RedemptionService::class)->redeemReferral($pending, null, '127.0.0.1');

        // The redemption itself is the eleventh event this week
        // (counter), 5/11 = 45 percent: still the jump rule.
        $this->assertTrue($result['reward']->hold);
        $this->assertContains('unattributed_share', $result['reward']->hold_rules);
    }

    public function test_existing_customer_gains_the_claim_email(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [, $shareToken] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');

        $outcome = app(EnrollmentService::class)->completeClaim(
            Membership::query()->where('share_token_hash', \App\Support\OpaqueToken::hash($shareToken))->firstOrFail(),
            ['first_name' => 'Fri', 'last_name' => 'End', 'phone_e164' => '+12125550777', 'email' => 'fri@example.test', 'consent' => true],
            '127.0.0.1',
            null,
        );

        $this->assertSame('created', $outcome['kind']);
        $this->assertSame('fri@example.test', $friend->fresh()->email);
    }

    public function test_self_claim_by_the_referrers_own_customer_record(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        $outcome = app(EnrollmentService::class)->completeClaim(
            $membership,
            ['first_name' => 'Reg', 'last_name' => 'Ular', 'phone_e164' => '+12125550999', 'email' => null, 'consent' => false],
            '127.0.0.1',
            null,
        );

        $this->assertSame('self_phone', $outcome['kind']);
        $this->assertSame(0, Referral::count());
    }

    public function test_small_service_branches(): void
    {
        // Nonce consume refuses empty input.
        $nonces = app(NonceService::class);
        $this->assertFalse($nonces->consume('referral', 1, null));
        $this->assertFalse($nonces->consume('referral', 1, ''));

        // A membership without a sealed copy has no plain token.
        $business = World::business();
        $customer = World::customer('+12125550624', 'No', 'Seal');
        $bare = Membership::create([
            'business_id' => $business->id, 'customer_id' => $customer->id,
            'share_token_hash' => \App\Support\OpaqueToken::hash(\App\Support\OpaqueToken::generate()),
            'share_token_state' => 'active', 'share_token_issued_at' => now(),
        ]);
        $this->assertNull($bare->plainShareToken());

        // Attribution: first-touch capture is write-once, and the
        // external referrer host lands in the payload.
        $service = app(AttributionService::class);
        $payload = $service->extract(request()->create('/?utm_source=google', 'GET', [], [], [], ['HTTP_REFERER' => 'https://www.google.com/search?q=x']));
        $this->assertSame('www.google.com', $payload['referrer_host'] ?? null);
        $service->captureForBusiness($business, $payload);
        $service->captureForBusiness($business, ['source' => 'other', 'referrer_host' => null]);
        $this->assertSame('google', $business->fresh()->acq_source);

        // Turnstile fails closed when the verifier call throws.
        config(['services.turnstile.site_key' => 'site']);
        config(['services.turnstile.secret_key' => 'secret']);
        Http::fake(fn () => throw new ConnectionException('down'));
        $this->assertFalse(app(TurnstileVerifier::class)->passes('token', '127.0.0.1'));

        // Segment counting: empty body and non-GSM bodies.
        $this->assertSame(0, SmsSegments::count(''));
        $this->assertSame(1, SmsSegments::count('漢字'));
        $gateway = new TwilioSmsGateway(new \Tests\Fakes\FakeTwilioHttpClient, 'ACx', 'auth');
        $this->assertSame(1, $gateway->segmentCount('hello'));
    }

    public function test_remaining_model_relations_resolve(): void
    {
        $business = World::business(['owner_text_notifications' => true]);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End', ['email' => 'fri@example.test']);
        [$referral] = World::referral($membership, $friend);
        app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');
        $referral->refresh();
        $reward = $referral->reward;

        $this->assertTrue($business->rewards->contains($reward));
        $this->assertSame($business->id, $referral->billingEvent->business->id);
        $this->assertSame($referral->id, $referral->billingEvent->referral->id);

        $alert = BusinessAlert::query()->firstOrFail();
        $this->assertSame($business->id, $alert->business->id);

        $event = ReferralEvent::query()->where('event_type', 'reward_minted')->firstOrFail();
        $this->assertSame($reward->id, $event->reward->id);

        $sms = \App\Models\SmsMessage::query()->firstOrFail();
        $this->assertSame($business->id, $sms->business->id);
        $this->assertSame(World::owner($business)->id === null ? null : $sms->business_id, $business->id);

        $consent = ConsentEvent::create([
            'membership_id' => $membership->id, 'customer_id' => $referrer->id, 'business_id' => $business->id,
            'event_type' => 'granted', 'source' => 'customer_checkbox', 'consent_wording_version' => 'v1',
            'flow' => 'enrollment', 'ip_address' => '127.0.0.1',
        ]);
        $this->assertSame($membership->id, $consent->membership->id);

        $history = CustomerNumberHistory::create([
            'customer_id' => $referrer->id, 'phone_e164' => '+12125550000', 'linked_at' => now(),
            'link_reason' => 'verified_with_new_number',
        ]);
        $this->assertSame($referrer->id, $history->customer->id);

        $attribution = app(AttributionService::class)->record('E3', $business);
        $this->assertSame($business->id, $attribution->business->id);
    }
}
