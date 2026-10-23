<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerNumberHistory;
use App\Models\FlowSession;
use App\Models\RateBudget;
use App\Models\ReferralEvent;
use App\Models\TokenNonce;
use App\Services\Attribution\AttributionService;
use App\Services\Referral\NonceService;
use App\Services\Referral\RedemptionService;
use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Probe replications for the Sprint 2 security verifier's
 * findings (sprint2-verifier-security.md): every probe that
 * demonstrated a defect on b767469 is restated here as a
 * regression test on the fixed behavior.
 */
class SecurityFixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
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

    private function flushCookies(): void
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->app->forgetInstance('session');
    }

    private function flowCookieValue($response): ?string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                return $cookie->getValue();
            }
        }

        return null;
    }

    public function test_m3_cooling_stamps_both_identities_and_bites_the_new_numbers_claims(): void
    {
        // The verifier's exploit shape: verify as number one, then
        // complete a claim verifying number two in the same flow.
        // The link used to stamp only the abandoned identity, so
        // claims held by the new-number customer were never cooled.
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $this->carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Ana', 'last_name' => 'Lee', 'phone' => '2125550111', 'consent' => '1',
        ]));
        $this->carry($this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share')));
        $original = Customer::query()->where('phone_e164', '+12125550111')->firstOrFail();

        $referrer = World::customer('+12125550888', 'Ola', 'Nord');
        [, $shareToken] = World::membership($business, $referrer);
        $this->carry($this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Ana', 'last_name' => 'Lee', 'phone' => '2125550333',
        ]));
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect();

        $newCustomer = Customer::query()->where('phone_e164', '+12125550333')->firstOrFail();
        $this->assertNotNull($original->fresh()->number_linked_at, 'prior identity stamped');
        $this->assertNotNull($newCustomer->fresh()->number_linked_at, 'completed-as identity stamped');
        $this->assertDatabaseHas('customer_number_history', [
            'customer_id' => $newCustomer->id, 'phone_e164' => '+12125550111',
        ]);
        $this->assertDatabaseHas('customer_number_history', [
            'customer_id' => $original->id, 'phone_e164' => '+12125550333',
        ]);

        // A reward held by the NEW-number identity, claimed inside
        // the window, now cools exactly like the old identity's.
        // (The membership is aged past the dormant threshold the
        // REF-54 hold requires; the link itself is minutes old.)
        $newMembership = $newCustomer->memberships()->where('business_id', $business->id)->firstOrFail();
        $newMembership->forceFill(['created_at' => now()->subDays(10)])->save();
        $hostReferral = \App\Models\Referral::query()
            ->where('referred_customer_id', $newCustomer->id)
            ->where('business_id', $business->id)
            ->firstOrFail();
        World::reward($newMembership, $hostReferral, [
            'available_at' => now()->subDay(), 'notification_state' => 'sent',
        ]);
        $this->travel(2)->days();
        $outcome = app(RedemptionService::class)->claimReward($newMembership->rewards()->firstOrFail(), null, '127.0.0.1');
        $this->assertSame('held', $outcome['outcome']);
        $this->assertContains('number_link_cooling', $outcome['reward']->hold_rules ?? []);
    }

    public function test_m3_link_never_fires_across_businesses(): void
    {
        $businessA = World::business();
        [, $qrA] = World::qr($businessA);
        $this->carry($this->get(route('refer.landing', ['token' => $qrA])));
        $this->carry($this->post(route('refer.enroll', ['token' => $qrA]), [
            'first_name' => 'Ana', 'last_name' => 'Lee', 'phone' => '2125550111', 'consent' => '1',
        ]));
        $this->carry($this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share')));

        // Same flow cookie, second business's share link, new number.
        $businessB = World::business();
        $referrerB = World::customer('+12125550444', 'Bob', 'Ray');
        [, $shareB] = World::membership($businessB, $referrerB);
        $this->carry($this->post(route('refer.friend.claim', ['token' => $shareB]), [
            'first_name' => 'Ana', 'last_name' => 'Lee', 'phone' => '2125550666',
        ]));
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect();

        $this->assertDatabaseMissing('referral_events', [
            'event_type' => 'number_linked', 'business_id' => $businessB->id,
        ]);
        $this->assertNull(Customer::query()->where('phone_e164', '+12125550666')->firstOrFail()->number_linked_at);
    }

    public function test_m4_deny_cannot_void_a_redeemed_referral_or_unclaim_a_reward(): void
    {
        // The verifier's probe, verbatim shape: redeem first, then
        // fire the owner deny route at the redeemed referral.
        $business = World::business();
        $owner = World::owner($business);
        $referrer = World::customer('+12125550111', 'Ana', 'Lee');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550222', 'Ben', 'Kim');
        [$referral] = World::referral($membership, $friend);

        $service = app(RedemptionService::class);
        $this->assertSame('redeemed', $service->redeemReferral($referral, null, '127.0.0.1')['outcome']);
        $this->assertSame(1, \App\Models\BillingEvent::query()->where('referral_id', $referral->id)->count());
        $reward = $referral->fresh()->reward;
        $this->assertNotNull($reward, 'redemption minted the referrer reward');

        $this->actingAs($owner)
            ->post(route('reviews.referrals.deny', ['referral' => $referral->id]))
            ->assertSessionHas('status', 'That referral has already been resolved and can no longer be denied.');
        $this->assertSame('redeemed', $referral->fresh()->state, 'deny refused after redemption');
        $this->assertSame(1, \App\Models\BillingEvent::query()->where('referral_id', $referral->id)->count(), 'billing record untouched');

        // The minted reward still vests and claims.
        $this->travel(15)->days();
        $reward->fresh()->applyVesting();
        $this->assertSame('claimed', $service->claimReward($reward->fresh(), null, '127.0.0.1')['outcome']);
    }

    public function test_m4_deny_cannot_unclaim_a_claimed_reward(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = World::customer('+12125550111', 'Ana', 'Lee');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550222', 'Ben', 'Kim');
        [$referral] = World::referral($membership, $friend);
        [$reward] = World::reward($membership, $referral);

        $service = app(RedemptionService::class);
        $this->assertSame('claimed', $service->claimReward($reward, null, '127.0.0.1')['outcome']);

        $this->actingAs($owner)
            ->post(route('reviews.rewards.deny', ['reward' => $reward->id]))
            ->assertSessionHas('status', 'That reward has already been resolved and can no longer be denied.');
        $this->assertSame('claimed', $reward->fresh()->state);
        $this->assertNotNull($reward->fresh()->claimed_at);
    }

    public function test_m1_nonce_consume_is_atomic_and_issue_invalidates(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550111', 'Ana', 'Lee');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550222', 'Ben', 'Kim');
        [$referral] = World::referral($membership, $friend);

        $nonces = app(NonceService::class);
        $first = $nonces->issue('referral', $referral->id);
        $second = $nonces->issue('referral', $referral->id);

        $this->assertFalse($nonces->consume('referral', $referral->id, $first), 're-render invalidated the first');
        $this->assertTrue($nonces->consume('referral', $referral->id, $second));
        $this->assertFalse($nonces->consume('referral', $referral->id, $second), 'single use, atomically');
        $this->assertFalse($nonces->consume('referral', $referral->id, null));
        $this->assertSame(1, TokenNonce::query()->whereNotNull('consumed_at')->count());
    }

    public function test_m2_qr_image_endpoints_resolve_their_token(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550111', 'Ana', 'Lee');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550222', 'Ben', 'Kim');
        [$hostReferral, $redeemToken] = World::referral($membership, $friend);
        [, $claimToken] = World::reward($membership, $hostReferral);

        $unknown = str_repeat('A', 43);
        $this->get(route('refer.redeem.qr', ['token' => $unknown]))->assertNotFound();
        $this->get(route('refer.claim.qr', ['token' => $unknown]))->assertNotFound();
        $this->get(route('refer.redeem.qr', ['token' => 'not-a-token']))->assertNotFound();
        $this->get(route('refer.redeem.qr', ['token' => $redeemToken]))->assertOk();
        $this->get(route('refer.claim.qr', ['token' => $claimToken]))->assertOk();
    }

    public function test_verification_rotates_the_flow_token(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $landing = $this->get(route('refer.landing', ['token' => $qrToken]));
        $beforeToken = $this->flowCookieValue($landing);
        $this->carry($landing);
        $this->carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Ana', 'last_name' => 'Lee', 'phone' => '2125550111', 'consent' => '1',
        ]));
        $checked = $this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertRedirect(route('refer.share'));
        $afterToken = $this->flowCookieValue($checked);

        $this->assertNotNull($beforeToken);
        $this->assertNotNull($afterToken);
        $this->assertNotSame($beforeToken, $afterToken, 'verification rotated the flow token');

        // The pre-verification token is dead; the rotated one works.
        $this->flushCookies();
        $this->withUnencryptedCookie('er2_flow', $beforeToken)
            ->get(route('refer.share'))->assertRedirect();
        $this->flushCookies();
        $this->withUnencryptedCookie('er2_flow', $afterToken)
            ->get(route('refer.share'))->assertOk();
    }

    public function test_claim_page_date_gate_also_binds_the_business(): void
    {
        // Verified at business A; a claim link at business B for the
        // same customer must not disclose the availability date.
        $businessA = World::business();
        [, $qrA] = World::qr($businessA);
        $this->carry($this->get(route('refer.landing', ['token' => $qrA])));
        $this->carry($this->post(route('refer.enroll', ['token' => $qrA]), [
            'first_name' => 'Ana', 'last_name' => 'Lee', 'phone' => '2125550111', 'consent' => '1',
        ]));
        $this->carry($this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share')));

        $customer = Customer::query()->where('phone_e164', '+12125550111')->firstOrFail();
        $businessB = World::business();
        [$membershipB] = World::membership($businessB, $customer);
        $friendB = World::customer('+12125550777', 'Cal', 'Doe');
        [$referralB] = World::referral($membershipB, $friendB);
        [, $claimB] = World::reward($membershipB, $referralB, [
            'state' => 'pending_vesting', 'available_at' => now()->addDays(9), 'notification_state' => 'queued',
        ]);

        $page = $this->get(route('refer.claim.page', ['token' => $claimB]))->assertOk();
        $page->assertDontSee('Available on', false);
    }

    public function test_prune_command_removes_only_dead_flow_state(): void
    {
        FlowSession::create([
            'id' => hash('sha256', 'dead-flow'), 'data' => [], 'expires_at' => now()->subHour(),
        ]);
        FlowSession::create([
            'id' => hash('sha256', 'live-flow'), 'data' => [], 'expires_at' => now()->addMinutes(10),
        ]);
        TokenNonce::create([
            'subject_type' => 'referral', 'subject_id' => 1,
            'nonce_hash' => hash('sha256', 'x'), 'expires_at' => now()->subHour(),
        ]);
        RateBudget::create(['key' => 'old', 'count' => 3, 'window_started_at' => now()->subDays(3)]);
        RateBudget::create(['key' => 'fresh', 'count' => 1, 'window_started_at' => now()]);

        $this->artisan('flow:prune')->assertSuccessful();

        $this->assertSame(1, FlowSession::count());
        $this->assertSame(0, TokenNonce::count());
        $this->assertSame(1, RateBudget::count());
        $this->assertSame('fresh', RateBudget::firstOrFail()->key);
    }

    public function test_webhook_stop_matching_tolerates_case_and_punctuation(): void
    {
        config(['services.twilio.webhook_auth_token' => 'test-auth-token']);

        $post = [
            'MessageSid' => 'SMstop1', 'From' => '+12125550111', 'Body' => 'stop.',
        ];
        $validator = new \Twilio\Security\RequestValidator('test-auth-token');
        $url = route('webhooks.twilio.status');
        $signature = $validator->computeSignature($url, $post);

        $this->call('POST', $url, $post, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $signature])
            ->assertOk();
        $this->assertDatabaseHas('sms_opt_outs', ['phone_e164' => '+12125550111']);
    }

    public function test_share_surfaces_degrade_when_the_verified_membership_vanishes(): void
    {
        // Defensive: the verified marker references a membership
        // deleted mid-flow (owner cleanup, say). Both share
        // surfaces redirect home instead of erroring.
        $business = World::business();
        [, $qrToken] = World::qr($business);
        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $this->carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Ana', 'last_name' => 'Lee', 'phone' => '2125550111', 'consent' => '1',
        ]));
        $this->carry($this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share')));

        \App\Models\Membership::query()->delete();

        $this->get(route('refer.share'))->assertRedirect(route('home'));
        $this->get(route('refer.share.qr'))->assertRedirect(route('home'));
    }

    public function test_attribution_payload_is_bounded_to_column_width(): void
    {
        $request = Request::create('/pricing?utm_source='.str_repeat('a', 400).'&utm_campaign=x', 'GET');
        $payload = app(AttributionService::class)->extract($request);

        $this->assertNotNull($payload);
        $this->assertSame(255, strlen((string) $payload['source']));
    }

    public function test_register_ticket_prefill_is_clamped_to_the_calculator_max(): void
    {
        $this->get(route('register', ['ticket' => '99999999']))->assertOk();
        $this->assertSame(100000, session('signup.prefill')['average_ticket_dollars']);
    }
}
