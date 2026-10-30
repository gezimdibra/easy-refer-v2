<?php

namespace Tests\Feature\Billing;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\SmsGateway;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Membership;
use App\Models\ReferralEvent;
use App\Services\Referral\RedemptionService;
use App\Services\Referral\RiskScoring;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Money fix train, fix 5 (business-logic audit 2.2; synthesis
 * item 5): the one-hour minimum-age soft signal.
 *
 * The v1 provenance record kept the one-hour rule in v2 as a
 * soft signal only, but no age rule existed anywhere in v2
 * code: a referral could be claimed and redeemed minutes
 * after creation with nothing recorded. The signal is now
 * recorded at redemption when the referral is younger than
 * one hour: a risk rule on the referral, a risk_signal event,
 * and a small score contribution. It is a signal only, never
 * a gate: it never holds the referral, never holds the
 * minted reward, and never blocks or un-bills the visit on
 * its own.
 */
class ReferralAgeSignalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    private function membership(Business $business): Membership
    {
        $referrer = World::customer('+12125550988', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        return $membership;
    }

    public function test_signal_fires_below_one_hour_and_not_at_or_above_it(): void
    {
        $business = World::business();
        $membership = $this->membership($business);
        $friend = World::customer('+12125550123', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);
        $risk = app(RiskScoring::class);

        $referral->forceFill(['created_at' => Carbon::parse('2026-03-10 12:00:00')])->save();

        $this->assertSame('referral_age_under_one_hour', $risk->minimumAgeSignal($referral, Carbon::parse('2026-03-10 12:00:00')));
        $this->assertSame('referral_age_under_one_hour', $risk->minimumAgeSignal($referral, Carbon::parse('2026-03-10 12:59:59')));
        $this->assertNull($risk->minimumAgeSignal($referral, Carbon::parse('2026-03-10 13:00:00')));
        $this->assertNull($risk->minimumAgeSignal($referral, Carbon::parse('2026-03-10 15:00:00')));
    }

    public function test_a_fast_redemption_records_the_signal_and_nothing_is_held_or_blocked(): void
    {
        Carbon::setTestNow('2026-03-10 12:00:00');
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $friend = World::customer('+12125550456', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);
        $referral->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $result = app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');

        $this->assertSame('redeemed', $result['outcome']);
        $fresh = $referral->fresh();
        $this->assertContains('referral_age_under_one_hour', $fresh->risk_rules ?? []);
        $this->assertSame((int) config('referrals.risk_points.referral_age_under_one_hour'), $fresh->risk_score);
        $this->assertFalse($fresh->hold);
        $this->assertFalse($result['reward']->hold);
        $this->assertSame(1, ReferralEvent::query()
            ->where('event_type', 'risk_signal')
            ->where('referral_id', $referral->id)
            ->count());
        // The visit bills normally: the signal is not a gate.
        $this->assertSame(1, BillingEvent::query()->where('referral_id', $referral->id)->count());
    }

    public function test_a_slow_redemption_records_no_age_signal(): void
    {
        Carbon::setTestNow('2026-03-10 12:00:00');
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);
        $friend = World::customer('+12125550789', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);
        $referral->forceFill(['created_at' => now()->subHours(3)])->save();

        $result = app(RedemptionService::class)->redeemReferral($referral, null, '127.0.0.1');

        $this->assertSame('redeemed', $result['outcome']);
        $this->assertNotContains('referral_age_under_one_hour', $referral->fresh()->risk_rules ?? []);
        $this->assertSame(0, ReferralEvent::query()->where('event_type', 'risk_signal')->count());
    }

    public function test_the_signal_points_stay_below_the_hold_threshold_on_their_own(): void
    {
        $points = (int) config('referrals.risk_points.referral_age_under_one_hour');
        $this->assertGreaterThan(0, $points);
        $this->assertFalse(app(RiskScoring::class)->holdsAt($points));
    }
}
