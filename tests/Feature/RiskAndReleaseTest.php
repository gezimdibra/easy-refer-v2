<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Mail\FirstVisitAlertMail;
use App\Mail\NewsletterConfirmationMail;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Membership;
use App\Models\QrCode;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Services\Referral\EnrollmentService;
use App\Services\Referral\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Risk scoring outcomes, review release paths, the first-visit
 * alert's SMS variant, and direct service outcome branches
 * (spec Sections 3.1, 5.6, 6.1).
 */
class RiskAndReleaseTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsGateway $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $this->sms);
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
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

    public function test_device_signal_reuse_holds_the_second_customers_referral(): void
    {
        $business = World::business();
        [, $qrToken] = World::qr($business);

        // Customer A enrolls with a device signal.
        $this->carry($this->get(route('refer.landing', ['token' => $qrToken])));
        $this->withHeader('X-Device-Signal', 'device-xyz')->carrylessPostEnroll($qrToken, '2125550111');
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect(route('refer.share'));
        $membershipA = Membership::query()->firstOrFail();

        $this->flushCookies();

        // Customer B claims through A's link from the SAME device.
        $this->carry($this->get(route('refer.friend', ['token' => $membershipA->plainShareToken()])));
        $this->withHeader('X-Device-Signal', 'device-xyz');
        $this->carry($this->post(route('refer.friend.claim', ['token' => $membershipA->plainShareToken()]), [
            'first_name' => 'Bee', 'last_name' => 'Two', 'phone' => '2125550222',
        ])->assertRedirect(route('refer.verify')));
        $this->post(route('refer.verify.check'), ['code' => '123456'])->assertRedirect();

        $referral = Referral::query()->firstOrFail();
        $this->assertTrue($referral->hold);
        $this->assertContains('device_signal_reuse', $referral->hold_rules);
        $this->assertSame(50, $referral->risk_score);
    }

    private function carrylessPostEnroll(string $qrToken, string $phone): void
    {
        $this->carry($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Ay', 'last_name' => 'One', 'phone' => $phone,
        ])->assertRedirect(route('refer.verify')));
    }

    public function test_referrer_concentration_holds_the_minted_reward(): void
    {
        $business = World::business(['daily_referral_cap' => 100, 'monthly_referral_cap' => 500]);
        $target = World::customer('+12125550999', 'Reg', 'Ular');
        [$targetMembership] = World::membership($business, $target);

        // Seven redemptions by others, three by the target, all in
        // the last 30 days.
        for ($i = 0; $i < 7; $i++) {
            $other = World::customer('+1212555'.str_pad((string) (300 + $i), 4, '0', STR_PAD_LEFT), 'Oth', 'Er');
            [$m] = World::membership($business, $other);
            World::referral($m, World::customer('+1212555'.str_pad((string) (400 + $i), 4, '0', STR_PAD_LEFT), 'Fri', 'End'), [
                'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(2),
            ]);
        }
        for ($i = 0; $i < 3; $i++) {
            World::referral($targetMembership, World::customer('+1212555'.str_pad((string) (500 + $i), 4, '0', STR_PAD_LEFT), 'Fri', 'End'), [
                'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(2),
            ]);
        }

        [$pending] = World::referral($targetMembership, World::customer('+12125550610', 'New', 'Friend'));
        $result = app(RedemptionService::class)->redeemReferral($pending, null, '127.0.0.1');

        $this->assertSame('redeemed', $result['outcome']);
        $reward = $result['reward'];
        $this->assertTrue($reward->hold);
        $this->assertContains('referrer_concentration', $reward->hold_rules);
        $this->assertDatabaseHas('referral_events', ['event_type' => 'flag_raised', 'reward_id' => $reward->id]);
    }

    public function test_unattributed_share_holds_the_minted_reward(): void
    {
        $business = World::business(['daily_referral_cap' => 100, 'monthly_referral_cap' => 500]);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        // Ten counter redemptions in the trailing week (events).
        for ($i = 0; $i < 10; $i++) {
            ReferralEvent::record('redemption', 'counter', $business, null, null, null, [], '127.0.0.1');
        }

        [$pending] = World::referral($membership, World::customer('+12125550611', 'New', 'Friend'));
        $result = app(RedemptionService::class)->redeemReferral($pending, null, '127.0.0.1');

        $this->assertTrue($result['reward']->hold);
        $this->assertContains('unattributed_share', $result['reward']->hold_rules);
    }

    public function test_near_total_conversion_holds_the_minted_reward(): void
    {
        $business = World::business(['daily_referral_cap' => 100, 'monthly_referral_cap' => 500]);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        // Nineteen other members joined this week; nineteen
        // redemptions already landed: 19/20 enrollments converted.
        for ($i = 0; $i < 19; $i++) {
            $c = World::customer('+1212555'.str_pad((string) (600 + $i), 4, '0', STR_PAD_LEFT), 'Mem', 'Ber');
            World::membership($business, $c);
            World::referral($membership, World::customer('+1212555'.str_pad((string) (700 + $i), 4, '0', STR_PAD_LEFT), 'Fri', 'End'), [
                'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
            ]);
        }

        [$pending] = World::referral($membership, World::customer('+12125550612', 'New', 'Friend'));
        $result = app(RedemptionService::class)->redeemReferral($pending, null, '127.0.0.1');

        $this->assertTrue($result['reward']->hold);
        $this->assertContains('near_total_conversion', $result['reward']->hold_rules);
    }

    public function test_redemption_burst_holds_the_minted_reward(): void
    {
        config(['referrals.anomaly.burst_minimum' => 5]);
        $business = World::business(['daily_referral_cap' => 100, 'monthly_referral_cap' => 500]);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        // A quiet baseline: 28 redemptions spread over 28 days (1
        // per day average for this hour's baseline of 1), then a
        // burst inside the last hour.
        for ($i = 0; $i < 28; $i++) {
            $m = $i % 3 === 0 ? $membership : World::membership($business, World::customer('+1212555'.str_pad((string) (800 + $i), 4, '0', STR_PAD_LEFT), 'Base', 'Line'))[0];
            World::referral($m, World::customer('+1212555'.str_pad((string) (900 + $i), 4, '0', STR_PAD_LEFT), 'Fri', 'End'), [
                'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(10),
            ]);
        }
        for ($i = 0; $i < 5; $i++) {
            World::referral($membership, World::customer('+1212555'.str_pad((string) (950 + $i), 4, '0', STR_PAD_LEFT), 'Fri', 'End'), [
                'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subMinutes(30),
            ]);
        }

        [$pending] = World::referral($membership, World::customer('+12125550613', 'New', 'Friend'));
        $result = app(RedemptionService::class)->redeemReferral($pending, null, '127.0.0.1');

        $this->assertTrue($result['reward']->hold);
        $this->assertContains('redemption_burst', $result['reward']->hold_rules);
    }

    public function test_monthly_velocity_holds_at_creation_and_redemption(): void
    {
        $business = World::business(['daily_referral_cap' => 100, 'monthly_referral_cap' => 1]);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);

        $service = app(RedemptionService::class);
        [$pending] = World::referral($membership, World::customer('+12125550614', 'New', 'Friend'));
        $result = $service->redeemReferral($pending, null, '127.0.0.1');
        $this->assertSame('held', $result['outcome']);
        $this->assertSame(['velocity_monthly'], $pending->fresh()->hold_rules);

        $enrollment = app(EnrollmentService::class);
        $this->assertSame('velocity_monthly', $enrollment->velocityBreach($business, $membership));
    }

    public function test_claim_outcome_branches(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        $service = app(RedemptionService::class);

        [$claimed] = World::reward($membership, $referral, ['state' => Reward::STATE_CLAIMED, 'claimed_at' => now()]);
        $this->assertSame('already', $service->claimReward($claimed, null, '127.0.0.1')['outcome']);

        $friendB = World::customer('+12125550615', 'Bee', 'Two');
        [$referralB] = World::referral($membership, $friendB, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        [$denied] = World::reward($membership, $referralB, ['state' => Reward::STATE_DENIED]);
        $this->assertSame('denied', $service->claimReward($denied, null, '127.0.0.1')['outcome']);

        $friendC = World::customer('+12125550616', 'Cee', 'Three');
        [$referralC] = World::referral($membership, $friendC, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        [$held] = World::reward($membership, $referralC, ['hold' => true, 'hold_rules' => ['redemption_burst'], 'held_at' => now()]);
        $this->assertSame('held', $service->claimReward($held, null, '127.0.0.1')['outcome']);

        $friendD = World::customer('+12125550617', 'Dee', 'Four');
        [$referralD] = World::referral($membership, $friendD, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        [$early] = World::reward($membership, $referralD, [
            'state' => Reward::STATE_PENDING_VESTING, 'available_at' => now()->addDays(5), 'notification_state' => 'queued',
        ]);
        $this->assertSame('not_yet', $service->claimReward($early, null, '127.0.0.1')['outcome']);
    }

    public function test_redeem_outcome_branches_for_terminal_states(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $service = app(RedemptionService::class);

        [$voided] = World::referral($membership, World::customer('+12125550618', 'Vee', 'One'), ['state' => Referral::STATE_VOIDED]);
        $this->assertSame('voided', $service->redeemReferral($voided, null, '127.0.0.1')['outcome']);

        [$expired] = World::referral($membership, World::customer('+12125550619', 'Ex', 'Two'), ['expires_at' => now()->subHour()]);
        $this->assertSame('expired', $service->redeemReferral($expired, null, '127.0.0.1')['outcome']);
    }

    public function test_release_of_a_vested_held_reward_sends_its_text(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(20)]);
        [$reward] = World::reward($membership, $referral, [
            'hold' => true, 'hold_rules' => ['redemption_burst'], 'held_at' => now(),
            'notification_state' => 'queued',
        ]);

        $this->actingAs($owner)
            ->post(route('reviews.rewards.release', ['reward' => $reward->id]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Reward released.');

        $this->assertFalse($reward->fresh()->hold);
        $this->assertDatabaseHas('referral_events', ['event_type' => 'review_release', 'reward_id' => $reward->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'review.reward_released']);
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame('sent', $reward->fresh()->notification_state);
    }

    public function test_first_visit_alert_sms_variant_and_idempotency(): void
    {
        $business = World::business(['owner_text_notifications' => true]);
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        [$referral] = World::referral($membership, World::customer('+12125550777', 'Fri', 'End'));

        app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');

        $alert = \App\Models\BusinessAlert::query()->firstOrFail();
        $this->assertSame('sent', $alert->notification_state);
        $this->assertCount(1, $this->sms->sent);
        $this->assertStringContainsString('Your first referred customer just visited', $this->sms->sent[0]['body']);
        $this->assertDatabaseHas('sms_messages', ['template_key' => 'first_visit_alert', 'delivery_state' => 'sent']);
        Mail::assertSent(FirstVisitAlertMail::class);

        // A replayed job changes nothing.
        (new \App\Jobs\SendFirstVisitAlert($business->id))->handle($this->sms);
        $this->assertCount(1, $this->sms->sent);
    }

    public function test_alert_mail_and_newsletter_mail_render_their_copy(): void
    {
        $business = World::business();

        $alertHtml = (new FirstVisitAlertMail($business))->render();
        $this->assertStringContainsString('Your first referred customer just visited', $alertHtml);
        $this->assertStringContainsString('Good news: your first referred customer just visited '.$business->name, $alertHtml);

        $lead = Lead::create(['kind' => 'newsletter', 'email' => 'lead@example.test', 'source' => 'footer', 'token_hash' => 'x']);
        $mail = new NewsletterConfirmationMail(route('newsletter.confirm', ['token' => 'abc']));
        $html = $mail->render();
        $this->assertStringContainsString('Confirm your subscription', $html);
        $this->assertSame('Confirm your subscription', $mail->envelope()->subject);
        $this->assertNotNull($lead);
    }

    public function test_staff_confirm_with_an_unknown_token_is_the_generic_page(): void
    {
        $this->post(route('redeem.confirm', ['token' => str_repeat('e', 43)]), ['nonce' => 'x'])
            ->assertNotFound()
            ->assertSee('This code is not active');
    }

    public function test_staff_screens_for_claimed_and_held_rewards(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);

        [$claimed, $claimedToken] = World::reward($membership, $referral, [
            'state' => Reward::STATE_CLAIMED, 'claimed_at' => now(),
        ]);
        $this->get(route('redeem.show', ['token' => $claimedToken]))
            ->assertOk()
            ->assertSee('Already claimed');

        $friendB = World::customer('+12125550620', 'Bee', 'Two');
        [$referralB] = World::referral($membership, $friendB, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        [$held, $heldToken] = World::reward($membership, $referralB, [
            'hold' => true, 'hold_rules' => ['redemption_burst'], 'held_at' => now(),
        ]);
        $this->get(route('redeem.show', ['token' => $heldToken]))
            ->assertOk()
            ->assertSee("This one needs the owner's review first", false);
    }

    public function test_qr_page_image_and_legacy_code_self_heal(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        // A legacy code with no sealed copy: the page revokes and
        // reissues rather than showing a dead code.
        $legacyToken = \App\Support\OpaqueToken::generate();
        $legacy = QrCode::create([
            'business_id' => $business->id,
            'token_hash' => \App\Support\OpaqueToken::hash($legacyToken),
            'token_encrypted' => null,
            'state' => 'active',
        ]);

        $this->actingAs($owner)->get(route('owner.qr'))
            ->assertOk()
            ->assertDontSee($legacyToken);

        $this->assertSame('revoked', $legacy->fresh()->state);
        $fresh = $business->activeQrCode();
        $this->assertNotNull($fresh->plainToken());

        $this->actingAs($owner)->get(route('owner.qr.image'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_model_relations_resolve(): void
    {
        $business = World::business();
        [$qr] = World::qr($business);
        $customer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $customer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);
        app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');
        $referral->refresh();
        $reward = $referral->reward;

        $this->assertSame($business->id, $membership->business->id);
        $this->assertSame($customer->id, $membership->customer->id);
        $this->assertTrue($business->customers->contains($customer));
        $this->assertSame($business->currentPlan->key, 'pay_per_visit');
        $this->assertTrue($business->qrCodes->contains($qr));
        $this->assertSame(1, $business->billingEvents->count());
        $this->assertSame(1, $business->alerts->count());
        $this->assertSame($business->id, $referral->business->id);
        $this->assertSame($membership->id, $referral->referrerMembership->id);
        $this->assertSame($friend->id, $referral->referredCustomer->id);
        $this->assertSame($referral->id, $referral->billingEvent->referral_id);
        $this->assertSame($business->id, $referral->billingEvent->business->id);
        $this->assertSame($membership->id, $reward->membership->id);
        $this->assertSame($referral->id, $reward->referral->id);
        $this->assertSame($business->id, $reward->business->id);
        $this->assertSame(1, $membership->referralsMade->count());
        $this->assertSame(1, $membership->rewards->count());
        $this->assertSame(1, $customer->memberships->count());
        $this->assertSame(0, $customer->numberHistory->count());
        $this->assertSame('Reg Ular', $customer->fullName());
        $this->assertTrue($business->currentPlan->businesses->contains($business));

        $event = ReferralEvent::query()->where('event_type', 'redemption')->firstOrFail();
        $this->assertSame($business->id, $event->business->id);
        $this->assertSame($referral->id, $event->referral->id);
        $this->assertSame($membership->id, $event->membership->id);

        $sms = \App\Models\SmsMessage::query()->first();
        if ($sms !== null) {
            $this->assertSame($business->id, $sms->business->id);
        }

        $consent = \App\Models\ConsentEvent::query()->first();
        $this->assertNull($consent);
    }

    private function flushCookies(): void
    {
        $this->app->forgetInstance('session');
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }
}
