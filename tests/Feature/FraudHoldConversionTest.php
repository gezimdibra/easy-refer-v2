<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Contracts\SquarePaymentsGateway;
use App\Jobs\SendRewardReadySms;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Services\Billing\MonthlyCharger;
use App\Services\Billing\SettlementService;
use App\Services\Referral\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Fraud-hold conversion (Option B, owner ruling 2026-10-28;
 * design pass night-loop/fraud-hold-conversion-design-pass.md,
 * V-B compute-at-redemption). The friend's redemption ALWAYS
 * completes; only the referrer's reward and its billing coverage
 * are held for owner review. Test IDs T1-T43 refer to the design
 * pass's test plan.
 */
class FraudHoldConversionTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsGateway $sms;

    private FakeSquarePaymentsGateway $gateway;

    private int $friendSequence = 8100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsGateway;
        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SmsGateway::class, $this->sms);
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        config(['billing.charges_enabled' => true]);
        Mail::fake();
    }

    private function membership(Business $business, string $phone = '+12125550988'): Membership
    {
        $referrer = World::customer($phone, 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        return $membership;
    }

    private function cardOnFile(Business $business): void
    {
        PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'sq_card_test',
            'card_brand' => 'VISA',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_'.$business->id.'_fh',
        ]);
    }

    private function pending(Membership $membership, array $overrides = []): array
    {
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral, $token] = World::referral($membership, $friend, $overrides);
        // Age the referral past the one-hour soft signal so the
        // age rule never confuses a hold assertion.
        $referral->forceFill(['created_at' => now()->subHours(3)])->save();

        return [$referral->fresh(), $token];
    }

    private function staffNonce(string $token): string
    {
        $response = $this->get(route('redeem.show', ['token' => $token]))->assertOk();
        preg_match('/name="nonce" value="([^"]+)"/', $response->getContent(), $m);
        $this->assertNotEmpty($m, 'staff screen carries a nonce');

        return $m[1];
    }

    // ---------------------------------------------------------------
    // T5/T6: velocity at redemption completes and holds the reward
    // ---------------------------------------------------------------

    public function test_velocity_daily_redemption_completes_and_holds_reward_and_billing(): void
    {
        $business = World::business(['daily_referral_cap' => 1, 'free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        [$first] = $this->pending($membership);
        [$second, $secondToken] = $this->pending($membership);

        $service = app(RedemptionService::class);
        $this->assertSame('redeemed', $service->redeemReferral($first, null, '127.0.0.1')['outcome']);

        $result = $service->redeemReferral($second, null, '127.0.0.1');
        $this->assertSame('redeemed', $result['outcome']);
        $second->refresh();
        $this->assertSame(Referral::STATE_REDEEMED, $second->state);
        $this->assertFalse((bool) $second->hold);
        $this->assertNull($second->hold_rules);

        $this->assertSame(2, BillingEvent::count());
        $heldEvent = BillingEvent::query()->where('referral_id', $second->id)->firstOrFail();
        $this->assertSame('held', $heldEvent->coverage);

        $this->assertSame(2, Reward::count());
        $reward = $result['reward'];
        $this->assertTrue($reward->hold);
        $this->assertSame(['velocity_daily'], $reward->hold_rules);
        $this->assertNotNull($reward->held_at);

        // Exactly one flag_raised, against the reward.
        $flags = ReferralEvent::query()->where('event_type', 'flag_raised')->get();
        $this->assertCount(1, $flags);
        $this->assertSame($reward->id, $flags[0]->reward_id);

        // T14: the friend's receipt renders redeemed, no held/review words.
        $this->get(route('refer.redeem.page', ['token' => $secondToken]))
            ->assertOk()
            ->assertSee('Reward redeemed')
            ->assertDontSee('Held for review')
            ->assertDontSee('being confirmed');
    }

    public function test_velocity_monthly_redemption_completes_and_holds_reward(): void
    {
        $business = World::business(['daily_referral_cap' => 100, 'monthly_referral_cap' => 1]);
        $membership = $this->membership($business);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);
        [$pending] = $this->pending($membership);

        $result = app(RedemptionService::class)->redeemReferral($pending, null, '127.0.0.1');
        $this->assertSame('redeemed', $result['outcome']);
        $this->assertSame(['velocity_monthly'], $result['reward']->hold_rules);
        $this->assertSame('held', BillingEvent::query()->where('referral_id', $pending->id)->firstOrFail()->coverage);
    }

    // ---------------------------------------------------------------
    // T7: device-reuse flag carried as data converts at redemption
    // ---------------------------------------------------------------

    public function test_device_reuse_flag_completes_and_holds_reward_and_billing(): void
    {
        $business = World::business();
        $membership = $this->membership($business);
        [$referral] = $this->pending($membership, [
            'risk_score' => 50, 'risk_rules' => ['device_signal_reuse'],
        ]);

        $result = app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');
        $this->assertSame('redeemed', $result['outcome']);
        $this->assertFalse((bool) $referral->fresh()->hold);
        $this->assertTrue($result['reward']->hold);
        $this->assertSame(['device_signal_reuse'], $result['reward']->hold_rules);
        $this->assertSame('held', BillingEvent::query()->where('referral_id', $referral->id)->firstOrFail()->coverage);
    }

    public function test_below_threshold_score_does_not_hold(): void
    {
        $business = World::business();
        $membership = $this->membership($business);
        [$referral] = $this->pending($membership, [
            'risk_score' => 10, 'risk_rules' => ['referral_age_under_one_hour'],
        ]);

        $result = app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');
        $this->assertSame('redeemed', $result['outcome']);
        $this->assertFalse((bool) $result['reward']->hold);
        $this->assertSame('billable', BillingEvent::query()->where('referral_id', $referral->id)->firstOrFail()->coverage);
    }

    // ---------------------------------------------------------------
    // T8: union of converted + anomaly rules on ONE reward/event
    // ---------------------------------------------------------------

    public function test_converted_and_anomaly_rules_union_on_one_held_reward(): void
    {
        $business = World::business(['daily_referral_cap' => 100, 'monthly_referral_cap' => 500]);
        $membership = $this->membership($business);
        // Concentration fixture (RiskAndReleaseTest pattern).
        for ($i = 0; $i < 7; $i++) {
            $other = World::customer('+1212555'.str_pad((string) (300 + $i), 4, '0', STR_PAD_LEFT), 'Oth', 'Er');
            [$m] = World::membership($business, $other);
            World::referral($m, World::customer('+1212555'.str_pad((string) (400 + $i), 4, '0', STR_PAD_LEFT), 'Fri', 'End'), [
                'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(2),
            ]);
        }
        for ($i = 0; $i < 3; $i++) {
            World::referral($membership, World::customer('+1212555'.str_pad((string) (500 + $i), 4, '0', STR_PAD_LEFT), 'Fri', 'End'), [
                'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(2),
            ]);
        }
        [$referral] = $this->pending($membership, [
            'risk_score' => 50, 'risk_rules' => ['device_signal_reuse'],
        ]);

        $result = app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');
        $this->assertSame('redeemed', $result['outcome']);
        $this->assertTrue($result['reward']->hold);
        $this->assertContains('device_signal_reuse', $result['reward']->hold_rules);
        $this->assertContains('referrer_concentration', $result['reward']->hold_rules);
        $this->assertSame(1, BillingEvent::query()->where('referral_id', $referral->id)->count());
        $this->assertSame('held', BillingEvent::query()->where('referral_id', $referral->id)->firstOrFail()->coverage);
        $this->assertSame(1, ReferralEvent::query()->where('event_type', 'flag_raised')->where('reward_id', $result['reward']->id)->count());
    }

    // ---------------------------------------------------------------
    // T9: F2 backstop still wins over a converted flag
    // ---------------------------------------------------------------

    public function test_flagged_referral_at_textless_business_hits_missing_text_backstop(): void
    {
        $business = World::business(['friend_reward_text' => null, 'referrer_reward_text' => null]);
        $membership = $this->membership($business);
        [$referral] = $this->pending($membership, [
            'risk_score' => 50, 'risk_rules' => ['device_signal_reuse'],
        ]);

        $result = app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');
        $this->assertSame('missing_reward_text', $result['outcome']);
        $this->assertSame(Referral::STATE_PENDING, $referral->fresh()->state);
        $this->assertSame(0, BillingEvent::count());
        $this->assertSame(0, Reward::count());
    }

    // ---------------------------------------------------------------
    // T10: double press on a flagged referral is exactly-once
    // ---------------------------------------------------------------

    public function test_double_redemption_of_flagged_referral_is_exactly_once(): void
    {
        $business = World::business();
        $membership = $this->membership($business);
        [$referral] = $this->pending($membership, [
            'risk_score' => 50, 'risk_rules' => ['device_signal_reuse'],
        ]);
        $service = app(RedemptionService::class);
        $this->assertSame('redeemed', $service->redeemReferral($referral, null, '127.0.0.1')['outcome']);
        $this->assertSame('already', $service->redeemReferral($referral->fresh(), null, '127.0.0.1')['outcome']);
        $this->assertSame(1, BillingEvent::count());
        $this->assertSame(1, Reward::count());
        $this->assertSame(1, ReferralEvent::query()->where('event_type', 'redemption')->count());
    }

    // ---------------------------------------------------------------
    // T12/T13: friend + staff surfaces for a flagged pending referral
    // ---------------------------------------------------------------

    public function test_flagged_pending_referral_renders_show_and_confirm_screens(): void
    {
        $business = World::business();
        $membership = $this->membership($business);
        [, $token] = $this->pending($membership, [
            'risk_score' => 50, 'risk_rules' => ['device_signal_reuse'],
        ]);

        // Friend page: the normal show state, absence-pinned.
        $this->get(route('refer.redeem.page', ['token' => $token]))
            ->assertOk()
            ->assertSee('Show this code to a staff member')
            ->assertSee('Good until')
            ->assertDontSee('Held for review')
            ->assertDontSee('being confirmed')
            ->assertDontSee('review');

        // Staff page: confirm screen with a nonce; the POST completes.
        $nonce = $this->staffNonce($token);
        $this->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $nonce])->assertRedirect();
        $this->assertSame(1, Referral::query()->where('state', Referral::STATE_REDEEMED)->count());
        $reward = Reward::query()->firstOrFail();
        $this->assertTrue($reward->hold);
        $this->assertSame(['device_signal_reuse'], $reward->hold_rules);
    }

    // ---------------------------------------------------------------
    // T16-T21: release money mechanics on a CONVERTED hold
    // ---------------------------------------------------------------

    /** A converted (velocity) held visit, free allowance exhausted. */
    private function convertedHeldVisit(Business $business): array
    {
        $membership = $this->membership($business);
        [$first] = $this->pending($membership);
        [$second] = $this->pending($membership);
        $service = app(RedemptionService::class);
        $service->redeemReferral($first, null, '127.0.0.1');
        $result = $service->redeemReferral($second, null, '127.0.0.1');
        $this->assertTrue($result['reward']->hold, 'precondition: second visit held');

        return [$result, BillingEvent::query()->where('referral_id', $second->id)->firstOrFail()];
    }

    public function test_converted_held_visit_bills_exactly_once_after_release(): void
    {
        $business = World::business(['daily_referral_cap' => 1, 'free_visits_remaining' => 0]);
        $this->cardOnFile($business);
        [$result, $event] = $this->convertedHeldVisit($business);

        // While held: settlement leaves it held, preview 0 for it,
        // and only the clean first visit can charge.
        app(SettlementService::class)->settleAndPersist($business);
        $this->assertSame('held', $event->fresh()->coverage);

        app(RedemptionService::class)->releaseReward($result['reward']);
        $this->assertSame('billable', $event->fresh()->coverage);

        $records = app(MonthlyCharger::class)->chargeAllMonths($business->fresh());
        $total = collect($records)->sum('amount_cents');
        $this->assertSame(400, $total, 'first (200) + released held visit (200), once each');
        $this->assertSame($event->fresh()->billing_record_id !== null, true);

        // Idempotency: second release + second charger run change nothing.
        app(RedemptionService::class)->releaseReward($result['reward']->fresh());
        $this->assertSame([], app(MonthlyCharger::class)->chargeAllMonths($business->fresh()));
        $this->assertSame(400, (int) BillingRecord::query()->sum('amount_cents'));
        $chargeCallsBefore = count($this->gateway->chargeCalls);
        app(MonthlyCharger::class)->chargeAllMonths($business->fresh());
        $this->assertSame($chargeCallsBefore, count($this->gateway->chargeCalls));
    }

    public function test_release_after_vesting_elapsed_makes_reward_available_and_texts_once(): void
    {
        $business = World::business(['daily_referral_cap' => 1]);
        [$result, ] = $this->convertedHeldVisit($business);
        $reward = $result['reward'];
        $this->assertSame('queued', $reward->notification_state);

        // Vesting elapses during review.
        $this->travel(15)->days();
        app(RedemptionService::class)->releaseReward($reward->fresh());

        $reward->refresh();
        $this->assertSame(Reward::STATE_AVAILABLE, $reward->state);
        $this->assertFalse((bool) $reward->hold);
        // The release dispatch runs the job synchronously in tests
        // (sync queue): exactly one text, and it passed the FH-OB1
        // guard because the reward is genuinely claimable.
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame('sent', $reward->fresh()->notification_state);
    }

    public function test_held_reward_stale_sms_job_sends_nothing_then_release_texts_exactly_once(): void
    {
        // Two-key Condition 1 (double-text interleaving, on ONE
        // reward): a stale SendRewardReadySms job dispatched
        // before the review is consumed by the FH-OB1 guard
        // while the reward is held, then the release dispatch
        // sends the one and only text.
        $business = World::business(['daily_referral_cap' => 1]);
        [$result, ] = $this->convertedHeldVisit($business);
        $reward = $result['reward'];
        $this->assertTrue((bool) $reward->hold, 'precondition: reward held');
        $this->assertSame('queued', $reward->notification_state);

        // Vesting elapses while the reward is still held, so the
        // only thing blocking the stale job is the hold itself.
        $this->travel(15)->days();

        // 1. Stale job while HELD: the FH-OB1 guard sends nothing
        // and leaves the notification queued for the release.
        (new SendRewardReadySms($reward->id))->handle($this->sms);
        $this->assertCount(0, $this->sms->sent);
        $this->assertSame('queued', $reward->fresh()->notification_state);

        // 2. Release after vesting has elapsed: the release
        // dispatch is the one text across the whole sequence.
        app(RedemptionService::class)->releaseReward($reward->fresh());

        $this->assertCount(1, $this->sms->sent);
        $this->assertSame(Reward::STATE_AVAILABLE, $reward->fresh()->state);
        $this->assertFalse((bool) $reward->fresh()->hold);
        $this->assertSame('sent', $reward->fresh()->notification_state);
    }

    public function test_vesting_clock_starts_at_redemption_not_release(): void
    {
        $business = World::business(['daily_referral_cap' => 1]);
        [$result, ] = $this->convertedHeldVisit($business);
        $reward = $result['reward'];
        $redeemedAt = $result['referral']->fresh()->redeemed_at;
        $this->assertEqualsWithDelta(14 * 86400, abs($reward->available_at->unix() - $redeemedAt->unix()), 120);

        $this->travel(3)->days();
        app(RedemptionService::class)->releaseReward($reward->fresh());
        $reward->refresh();
        $this->assertSame(Reward::STATE_PENDING_VESTING, $reward->state);
        $this->assertEqualsWithDelta(14 * 86400, abs($reward->available_at->unix() - $redeemedAt->unix()), 120, 'release must not restart vesting');
        $this->assertSame('not_yet', app(RedemptionService::class)->claimReward($reward, null, '127.0.0.1')['outcome']);
    }

    public function test_released_held_visit_takes_free_credit_when_allowance_remains(): void
    {
        $business = World::business(['daily_referral_cap' => 1, 'free_visits_remaining' => 5]);
        [$result, $event] = $this->convertedHeldVisit($business);
        $before = (int) $business->fresh()->free_visits_remaining;

        app(RedemptionService::class)->releaseReward($result['reward']);
        app(SettlementService::class)->settleAndPersist($business->fresh());

        // Both visits settle free: the held one consumed nothing
        // while held and exactly one slot after release.
        $this->assertSame('free_credit', $event->fresh()->coverage);
        $this->assertSame($before - 2, (int) $business->fresh()->free_visits_remaining);
        $this->assertNull(app(MonthlyCharger::class)->charge($business->fresh()));
    }

    public function test_month_roll_release_charges_in_redemption_month_once(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(2));
        $business = World::business(['daily_referral_cap' => 1, 'free_visits_remaining' => 0]);
        $this->cardOnFile($business);
        [$result, $event] = $this->convertedHeldVisit($business);
        $redemptionMonth = $event->created_at->format('Y-m');

        $this->travel(40)->days();
        app(RedemptionService::class)->releaseReward($result['reward']->fresh());
        $records = app(MonthlyCharger::class)->chargeAllMonths($business->fresh());
        $this->assertNotEmpty($records);
        $arrears = collect($records)->first(fn ($r) => $r->id === $event->fresh()->billing_record_id);
        $this->assertNotNull($arrears, 'released event claimed by a record');
        $this->assertSame([], app(MonthlyCharger::class)->chargeAllMonths($business->fresh()), 'arrears charged exactly once');
        $this->assertNotNull($redemptionMonth);
    }

    public function test_cap_boundary_released_held_visit_settles_over_cap(): void
    {
        $business = World::business(['daily_referral_cap' => 100, 'monthly_referral_cap' => 500, 'free_visits_remaining' => 0]);
        $this->cardOnFile($business);
        $membership = $this->membership($business);

        // 49 clean billable visits claimed by a charge. Seeded as
        // ledger rows directly: redeeming 49 visits live in one
        // test minute would (correctly) trip the burst /
        // unattributed / concentration anomaly rules and hold
        // them, which is a different test's subject.
        for ($i = 0; $i < 49; $i++) {
            $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
            [$r] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(2)]);
            BillingEvent::create(['business_id' => $business->id, 'referral_id' => $r->id, 'amount_cents' => 200, 'coverage' => 'billable']);
        }
        app(SettlementService::class)->settleAndPersist($business);
        app(MonthlyCharger::class)->chargeAllMonths($business->fresh());
        $this->assertSame(9800, (int) BillingRecord::query()->sum('amount_cents'));

        // One converted held visit (device flag), then released.
        [$flagged] = $this->pending($membership, ['risk_score' => 50, 'risk_rules' => ['device_signal_reuse']]);
        $result = app(RedemptionService::class)->redeemReferral($flagged, null, '127.0.0.1');
        $event = BillingEvent::query()->where('referral_id', $flagged->id)->firstOrFail();
        $this->assertSame('held', $event->coverage);
        app(RedemptionService::class)->releaseReward($result['reward']);
        app(SettlementService::class)->settleAndPersist($business->fresh());

        $this->assertSame('over_cap', $event->fresh()->coverage);
        $this->assertSame(9800, (int) BillingRecord::query()->sum('amount_cents'), 'month never exceeds $98 billed');
    }

    // ---------------------------------------------------------------
    // T22-T24/T28: deny on a converted hold
    // ---------------------------------------------------------------

    public function test_converted_held_visit_denied_never_bills_and_restores_no_free_slot(): void
    {
        $business = World::business(['daily_referral_cap' => 1, 'free_visits_remaining' => 3]);
        $this->cardOnFile($business);
        [$result, $event] = $this->convertedHeldVisit($business);

        // A settlement pass while held must leave it held. (The
        // clean first visit settles free_credit in this pass and
        // consumes its slot; the baseline for the no-restore
        // assertion is taken AFTER it, so the only visit whose
        // slot behavior is measured is the held one.)
        app(SettlementService::class)->settleAndPersist($business);
        $this->assertSame('held', $event->fresh()->coverage);
        $freeBefore = (int) $business->fresh()->free_visits_remaining;

        $this->assertTrue(app(RedemptionService::class)->denyReward($result['reward']));

        $this->assertSame(Reward::STATE_DENIED, $result['reward']->fresh()->state);
        $this->assertSame('fraud_denied', $event->fresh()->coverage);
        $this->assertSame($freeBefore, (int) $business->fresh()->free_visits_remaining, 'no slot consumed, none restored');

        // Never promoted back, never charged.
        app(SettlementService::class)->settleAndPersist($business->fresh());
        $this->assertSame('fraud_denied', $event->fresh()->coverage);
        $records = app(MonthlyCharger::class)->chargeAllMonths($business->fresh());
        $this->assertSame(0, collect($records)->sum('amount_cents'), 'the clean first visit settled free_credit; the denied visit charges nothing');
        $this->assertNull($event->fresh()->billing_record_id, 'the denied event is claimed by no record');

        // Referral stays redeemed; friend receipt stands; claim denied.
        $this->assertSame(Referral::STATE_REDEEMED, $result['referral']->fresh()->state);
        $this->assertSame('denied', app(RedemptionService::class)->claimReward($result['reward']->fresh(), null, '127.0.0.1')['outcome']);

        // Deny-then-release cannot resurrect billing.
        app(RedemptionService::class)->releaseReward($result['reward']->fresh());
        $this->assertSame('fraud_denied', $event->fresh()->coverage);
    }

    // ---------------------------------------------------------------
    // T29/T30: owner queue + referrals list for a converted visit
    // ---------------------------------------------------------------

    public function test_reviews_queue_shows_exactly_one_reward_row_for_converted_visit(): void
    {
        $business = World::business(['daily_referral_cap' => 1]);
        $owner = World::owner($business);
        [$result, ] = $this->convertedHeldVisit($business);

        $response = $this->actingAs($owner)->get(route('reviews'));
        $response->assertOk();
        $response->assertSee('This referrer reached the daily limit');
        $this->assertSame(1, Referral::query()->where('hold', true)->count() + Reward::query()->where('hold', true)->count());
        $this->assertSame(0, Referral::query()->where('hold', true)->count());
        $this->assertSame($result['reward']->id, Reward::query()->where('hold', true)->firstOrFail()->id);
    }

    // ---------------------------------------------------------------
    // T36-T39: referrer state-truth on share/claim surfaces
    // ---------------------------------------------------------------

    public function test_held_converted_reward_surfaces_name_no_date_and_no_rule(): void
    {
        $business = World::business();
        $membership = $this->membership($business);
        [$referral] = $this->pending($membership, ['risk_score' => 50, 'risk_rules' => ['device_signal_reuse']]);
        $result = app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');
        $claimToken = $result['reward']->claim_token_encrypted;

        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('Held for review')
            ->assertSee('being confirmed')
            ->assertDontSee('will be available on')
            ->assertDontSee('Available on')
            ->assertDontSee('fraud')
            // Rule IDs are the disclosure that must never reach
            // the referrer (the bare word "device" appears in
            // unrelated page boilerplate and is not a disclosure).
            ->assertDontSee('device_signal_reuse')
            ->assertDontSee('velocity_daily')
            ->assertDontSee('velocity_monthly');
    }
}
