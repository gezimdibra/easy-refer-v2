<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Services\Referral\EnrollmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * GAP-AT-REDEMPTION acceptance (design pass Section 6, named
 * scenario): "Pair scans in-store, attempts redemption inside
 * the window, blocked at redemption with the honest time
 * named, no wait screen shown at claim." Owner rulings
 * 2026-10-29 23:31 + 23:33 EDT. [GAP-RDM-01] is valid ONLY in
 * the blocked state; the token survives a blocked attempt.
 */
class GapAtRedemptionTest extends TestCase
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

    private function carryFlowCookie(\Illuminate\Testing\TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    /** @return array{0: Membership, 1: string} referrer aged $secondsAgo (the anchor) */
    private function referrerAged(Business $business, string $phone, int $secondsAgo): array
    {
        $customer = World::customer($phone, 'Pat', 'Referrer');
        [$membership, $token] = World::membership($business, $customer);
        $membership->forceFill(['created_at' => now()->subSeconds($secondsAgo)])->saveQuietly();

        return [$membership->refresh(), $token];
    }

    /** @param array<string, mixed> $overrides */
    private function claim(Membership $referrer, array $overrides = []): array
    {
        return app(EnrollmentService::class)->completeClaim($referrer, array_merge([
            'first_name' => 'Jo',
            'last_name' => 'Kim',
            'phone_e164' => '+12125550212',
            'consent' => true,
        ], $overrides), '127.0.0.1', null);
    }

    private function gapMessage(Business $business, Membership $referrer): string
    {
        $unlock = $business->localTime($referrer->created_at->copy()->addMinutes(25));

        return 'This reward opens at '.$unlock->format('g:i A').'. The visit is still saved, and this same code will work after that time.';
    }

    public function test_c1_c2_c3_pair_blocked_at_redemption_time_named_token_whole(): void
    {
        $business = World::business();
        [$referrer, $shareToken] = $this->referrerAged($business, '+12125550901', 120);
        $unlockLocal = $business->localTime($referrer->created_at->copy()->addMinutes(25))->format('g:i A');

        // C1: the HTTP claim completes instantly inside the window.
        $this->carryFlowCookie($this->get(route('refer.friend', ['token' => $shareToken]))->assertOk());
        $this->carryFlowCookie($this->post(route('refer.friend.claim', ['token' => $shareToken]), [
            'first_name' => 'Jo', 'last_name' => 'Kim', 'phone' => '2125550212', 'consent' => '1', 'send_link' => '1',
        ])->assertRedirect(route('refer.verify')));

        $claimResponse = $this->post(route('refer.verify.check'), ['code' => '123456']);
        $referral = Referral::first();
        $this->assertNotNull($referral);
        $claimResponse->assertRedirect(route('refer.redeem.page', ['token' => $referral->redeem_token_encrypted]));
        $this->assertSame(Referral::STATE_PENDING, $referral->state);
        $this->assertSame(1, (int) $referrer->refresh()->referral_count);
        $this->assertDatabaseHas('sms_messages', ['template_key' => 'friend_link']);
        $this->assertDatabaseMissing('referral_events', ['event_type' => 'claim_too_soon']);
        $this->assertDatabaseMissing('sms_messages', ['template_key' => 'claim_unlock']);

        // C2: the ready screen is the shipped screen, no lock copy.
        $visit = $this->get(route('refer.redeem.page', ['token' => $referral->redeem_token_encrypted]))->assertOk();
        $visit->assertSee('Ready');
        $visit->assertSee('Show this code to a staff member. They scan it with their own phone.');
        $visit->assertSee('Good until');
        $visit->assertDontSee('right away');
        $visit->assertDontSee('opens at');
        $visit->assertDontSee($unlockLocal);

        // C3: staff attempt inside the window is blocked.
        $snapshot = Referral::find($referral->id)->getAttributes();
        $blocked = $this->get(route('redeem.show', ['token' => $referral->redeem_token_encrypted]))->assertOk();
        $blocked->assertSee('Not yet');
        $blocked->assertSee('Opens '.$unlockLocal);
        $blocked->assertSee('For Jo');
        $blocked->assertSee($this->gapMessage($business, $referrer));
        $blocked->assertSee('Check again');
        $blocked->assertDontSee('Mark as Redeemed');
        $blocked->assertDontSee('name="nonce"', false);
        $this->assertStringNotContainsString('redeem.confirm', $blocked->getContent());
        foreach (['fraud', 'gaming', 'suspicious', 'policy', 'review'] as $word) {
            $blocked->assertDontSee($word, false);
        }
        $this->assertSame(1, ReferralEvent::where('referral_id', $referral->id)->where('event_type', 'redemption_blocked_gap')->count());
        $gapEvent = ReferralEvent::where('referral_id', $referral->id)->where('event_type', 'redemption_blocked_gap')->first();
        $this->assertSame($referrer->created_at->copy()->addMinutes(25)->setTimezone(new \DateTimeZone('UTC'))->toIso8601String(), $gapEvent->metadata['unlock_at'] ?? null);

        // Direct POSTs (forged nonce, no nonce) cannot complete
        // it either, and the row stays byte-identical.
        $this->post(route('redeem.confirm', ['token' => $referral->redeem_token_encrypted]), ['nonce' => 'forged']);
        $this->post(route('redeem.confirm', ['token' => $referral->redeem_token_encrypted]), []);

        $this->assertSame($snapshot, Referral::find($referral->id)->getAttributes());
        $this->assertSame(0, BillingEvent::count());
        $this->assertSame(0, Reward::count());
        $this->assertDatabaseMissing('referral_events', ['referral_id' => $referral->id, 'event_type' => 'redemption']);
        $this->assertDatabaseMissing('referral_events', ['referral_id' => $referral->id, 'event_type' => 'flag_raised']);
    }

    public function test_c3_service_guard_blocks_a_direct_confirm_with_a_live_nonce_shape(): void
    {
        // A nonce minted pre-window cannot exist for a referral
        // created inside the window, so the service guard is
        // proven directly: redeemReferral returns too_early and
        // writes nothing but the audit event.
        $business = World::business();
        [$referrer] = $this->referrerAged($business, '+12125550902', 120);
        $done = $this->claim($referrer);
        $referral = $done['referral'];
        $snapshot = Referral::find($referral->id)->getAttributes();

        $result = app(\App\Services\Referral\RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');

        $this->assertSame('too_early', $result['outcome']);
        $this->assertSame($referrer->created_at->copy()->addMinutes(25)->timestamp, $result['unlock_at']->timestamp);
        $this->assertSame($snapshot, Referral::find($referral->id)->getAttributes());
        $this->assertSame(0, BillingEvent::count());
        $this->assertSame(0, Reward::count());
        $this->assertSame(1, ReferralEvent::where('event_type', 'redemption_blocked_gap')->count());
    }

    public function test_c4_conjunction_inside_window_no_hold_is_created(): void
    {
        $business = World::business();
        [$referrer] = $this->referrerAged($business, '+12125550903', 120);
        $done = $this->claim($referrer);
        $referral = $done['referral'];
        $referral->forceFill(['risk_score' => 50, 'risk_rules' => ['device_signal_reuse']])->save();

        $blocked = $this->get(route('redeem.show', ['token' => $referral->redeem_token_encrypted]))->assertOk();
        $blocked->assertSee($this->gapMessage($business, $referrer));
        $blocked->assertDontSee('review');
        $this->assertSame(0, Reward::count());
        $this->assertSame(0, BillingEvent::count());
        $this->assertDatabaseMissing('referral_events', ['referral_id' => $referral->id, 'event_type' => 'flag_raised']);
    }

    public function test_c5_retry_after_the_window_completes_exactly_once_clean(): void
    {
        $this->runC5(false);
    }

    public function test_c5_retry_after_the_window_completes_with_option_b_hold_intact(): void
    {
        $this->runC5(true);
    }

    private function runC5(bool $flagged): void
    {
        {
            $business = World::business();
            [$referrer] = $this->referrerAged($business, '+12125550904', 120);
            $done = $this->claim($referrer);
            $referral = $done['referral'];
            if ($flagged) {
                $referral->forceFill(['risk_score' => 50, 'risk_rules' => ['device_signal_reuse']])->save();
            }
            $unlock = $referrer->created_at->copy()->addMinutes(25);

            Carbon::setTestNow($unlock->copy()->subSecond());
            try {
                $this->get(route('redeem.show', ['token' => $referral->redeem_token_encrypted]))
                    ->assertOk()->assertSee($this->gapMessage($business, $referrer));
            } finally {
                Carbon::setTestNow();
            }

            Carbon::setTestNow($unlock);
            try {
                $show = $this->get(route('redeem.show', ['token' => $referral->redeem_token_encrypted]))->assertOk();
                $show->assertSee('Ready to redeem');
                $show->assertSee('Mark as Redeemed');
                preg_match('/name="nonce" value="([^"]+)"/', $show->getContent(), $m);
                $this->assertNotEmpty($m);
                $this->post(route('redeem.confirm', ['token' => $referral->redeem_token_encrypted]), ['nonce' => $m[1]])
                    ->assertRedirect(route('redeem.show', ['token' => $referral->redeem_token_encrypted]));

                $referral->refresh();
                $this->assertSame(Referral::STATE_REDEEMED, $referral->state);
                $this->assertSame(1, BillingEvent::where('referral_id', $referral->id)->count());
                $event = BillingEvent::where('referral_id', $referral->id)->first();
                $reward = $referral->reward;
                $this->assertNotNull($reward);
                if ($flagged) {
                    $this->assertTrue($reward->hold);
                    $this->assertSame('held', $event->coverage);
                } else {
                    $this->assertFalse($reward->hold);
                    $this->assertSame('billable', $event->coverage);
                }
                // Vesting anchors at redemption, exactly.
                $this->assertSame(
                    $referral->redeemed_at->copy()->addDays((int) $business->reward_vesting_days)->timestamp,
                    $reward->available_at->timestamp
                );

                // Second press: already-done, counts unchanged.
                $show2 = $this->get(route('redeem.show', ['token' => $referral->redeem_token_encrypted]))->assertOk();
                $this->assertSame(1, BillingEvent::count());
                $this->assertSame(1, Reward::count());
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    public function test_c6_aged_control_redeems_with_no_blocked_screen(): void
    {
        $business = World::business();
        [$referrer] = $this->referrerAged($business, '+12125550905', 60 * 60 * 48);
        $done = $this->claim($referrer);
        $referral = $done['referral'];

        $show = $this->get(route('redeem.show', ['token' => $referral->redeem_token_encrypted]))->assertOk();
        $show->assertSee('Ready to redeem');
        $show->assertSee('Mark as Redeemed');
        $show->assertDontSee('This reward opens at');
        $this->assertSame(0, ReferralEvent::where('event_type', 'redemption_blocked_gap')->count());
    }

    public function test_c8_in_flight_too_soon_era_rows_simply_complete(): void
    {
        // A legacy M3-shaped claim_too_soon row exists in
        // history; under the new code nothing reads it, no sweep
        // is registered, and the friend's claim completes.
        $business = World::business();
        [$referrer] = $this->referrerAged($business, '+12125550906', 120);
        ReferralEvent::record('claim_too_soon', 'customer', $business, null, null, $referrer, [
            'referrer_membership_id' => $referrer->id,
            'unlock_at' => now()->addMinutes(23)->toIso8601String(),
        ], '127.0.0.1');

        $done = $this->claim($referrer);
        $this->assertSame('created', $done['kind']);
        $this->assertFalse(class_exists(\App\Console\Commands\SweepClaimUnlocks::class));
        $this->assertFalse(class_exists(\App\Jobs\SendClaimUnlockSms::class));
        $schedule = file_get_contents(base_path('routes/console.php'));
        $this->assertStringNotContainsString('SweepClaimUnlocks', (string) $schedule);
    }

    public function test_c9_reward_token_claims_never_evaluate_the_gap(): void
    {
        $business = World::business();
        [$referrer] = $this->referrerAged($business, '+12125550907', 60);
        $friend = World::customer('+12125550212', 'Jo', 'Kim');
        [$referral] = World::referral($referrer, $friend);
        [$reward, $claimToken] = World::reward($referrer, $referral);

        $show = $this->get(route('redeem.show', ['token' => $claimToken]))->assertOk();
        $show->assertSee('Ready to claim');
        preg_match('/name="nonce" value="([^"]+)"/', $show->getContent(), $m);
        $this->assertNotEmpty($m);
        $this->post(route('redeem.confirm', ['token' => $claimToken]), ['nonce' => $m[1]])
            ->assertRedirect(route('redeem.show', ['token' => $claimToken]));
        $this->assertSame(Reward::STATE_CLAIMED, $reward->refresh()->state);
        $this->assertSame(0, ReferralEvent::where('event_type', 'redemption_blocked_gap')->count());
    }

    public function test_gap_before_missing_reward_text_inside_the_window(): void
    {
        $business = World::business(['friend_reward_text' => null, 'referrer_reward_text' => null]);
        [$referrer] = $this->referrerAged($business, '+12125550908', 120);
        $friend = World::customer('+12125550212', 'Jo', 'Kim');
        [$referral, $plain] = World::referral($referrer, $friend);

        $blocked = $this->get(route('redeem.show', ['token' => $plain]))->assertOk();
        $blocked->assertSee('This reward opens at');
        $blocked->assertDontSee('has not set its reward');
    }

}
