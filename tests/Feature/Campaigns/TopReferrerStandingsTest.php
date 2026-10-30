<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\MarketingSmsGateway;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\SmsOptOut;
use App\Services\Campaigns\TopReferrerStandings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * ID-02 (Amendment 48): the counting law. A settled visit is
 * redeemed + coverage not held/fraud_denied/refunded + the
 * referrer reward not held/denied, bucketed by redeemed_at in
 * the BUSINESS's month. Vesting never blocks.
 * memberships.referral_count is never a source.
 */
class TopReferrerStandingsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 9100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MarketingSmsGateway::class, new FakeMarketingSmsGateway);
        // Mid-window: Nov 10 2026, business-local. Standings month = 2026-10.
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-10 09:00:00', 'America/New_York'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(Business $business, string $first = 'Sam'): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone, $first, 'Reed'));
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

        return $membership->fresh();
    }

    /**
     * One visit by a fresh friend, redeemed in October 2026
     * unless overridden, with its billing event and reward.
     */
    private function visit(Membership $referrer, array $opts = []): Referral
    {
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        $friend = World::customer($phone, 'Fri', 'End');
        [$referral] = World::referral($referrer, $friend, [
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => Carbon::createFromFormat('Y-m-d H:i:s', $opts['redeemed_at'] ?? '2026-10-15 12:00:00', 'America/New_York')->setTimezone('UTC'),
        ]);

        if (($opts['coverage'] ?? 'billable') !== null) {
            BillingEvent::create([
                'business_id' => $referrer->business_id,
                'referral_id' => $referral->id,
                'amount_cents' => 200,
                'coverage' => $opts['coverage'] ?? 'billable',
            ]);
        }

        World::reward($referrer, $referral, $opts['reward'] ?? []);

        return $referral;
    }

    private function visits(Membership $referrer, int $n, array $opts = []): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->visit($referrer, $opts);
        }
    }

    public function test_settled_visits_count_and_vesting_never_blocks(): void
    {
        $business = World::business();
        $leader = $this->member($business);
        // pending_vesting is the World::reward default state
        // family (available_at past, state available); force a
        // vesting-pending reward explicitly on one visit.
        $this->visits($leader, 2);
        $this->visit($leader, ['reward' => ['state' => Reward::STATE_PENDING_VESTING, 'available_at' => now()->addDays(10)]]);
        $this->visit($leader, ['coverage' => 'free_credit']);
        $this->visit($leader, ['coverage' => 'over_cap']);

        $counts = TopReferrerStandings::countsForMonth($business, '2026-10');
        $this->assertSame(5, $counts[$leader->id] ?? 0);

        $leaders = TopReferrerStandings::leadersForMonth($business, '2026-10');
        $this->assertSame('sole', $leaders['form']);
        $this->assertSame(5, $leaders['count']);
    }

    public function test_held_denied_and_refunded_visits_never_count(): void
    {
        $business = World::business();
        $leader = $this->member($business);
        $this->visits($leader, 3);
        $this->visit($leader, ['coverage' => 'held', 'reward' => ['hold' => true, 'hold_rules' => ['velocity_daily']]]);
        $this->visit($leader, ['coverage' => 'fraud_denied', 'reward' => ['state' => Reward::STATE_DENIED]]);
        $this->visit($leader, ['coverage' => 'refunded', 'reward' => ['state' => Reward::STATE_DENIED]]);
        // A reward held under clawback alone also excludes.
        $this->visit($leader, ['reward' => ['hold' => true, 'hold_rules' => ['refund_clawback']]]);

        $counts = TopReferrerStandings::countsForMonth($business, '2026-10');
        $this->assertSame(3, $counts[$leader->id] ?? 0);
    }

    public function test_a_released_hold_settles_into_its_redemption_month(): void
    {
        $business = World::business();
        $leader = $this->member($business);
        $this->visits($leader, 2);
        $held = $this->visit($leader, ['coverage' => 'held', 'reward' => ['hold' => true]]);

        $this->assertNull(TopReferrerStandings::leadersForMonth($business, '2026-10'), 'below the floor while held');

        $held->billingEvent->forceFill(['coverage' => 'billable'])->save();
        $held->reward->forceFill(['hold' => false])->save();

        $leaders = TopReferrerStandings::leadersForMonth($business, '2026-10');
        $this->assertSame(3, $leaders['count']);
    }

    public function test_pending_expired_and_voided_referrals_contribute_nothing(): void
    {
        $business = World::business();
        $leader = $this->member($business);
        foreach ([Referral::STATE_PENDING, Referral::STATE_EXPIRED, Referral::STATE_VOIDED] as $state) {
            $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
            World::referral($leader, World::customer($phone), ['state' => $state]);
        }
        $this->visits($leader, 2);

        $this->assertSame(2, TopReferrerStandings::countsForMonth($business, '2026-10')[$leader->id] ?? 0);
        $this->assertNull(TopReferrerStandings::leadersForMonth($business, '2026-10'), 'floor: 2 crowns nobody');
    }

    public function test_referral_count_is_never_a_source_claim_inflated_fixture(): void
    {
        $business = World::business();
        $claimer = $this->member($business, 'Cla');
        $real = $this->member($business, 'Rea');
        // The claim-inflated fixture: many claims created
        // (referral_count high), zero settled visits.
        $claimer->forceFill(['referral_count' => 40])->save();
        for ($i = 0; $i < 6; $i++) {
            $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
            World::referral($claimer, World::customer($phone));
        }
        $this->visits($real, 3);

        $leaders = TopReferrerStandings::leadersForMonth($business, '2026-10');
        $this->assertSame([$real->id], array_map(fn ($m) => $m->id, $leaders['leaders']));
        $this->assertSame(0, TopReferrerStandings::countsForMonth($business, '2026-10')[$claimer->id] ?? 0);
    }

    public function test_floor_boundary_two_is_silence_three_crowns(): void
    {
        $business = World::business();
        $leader = $this->member($business);
        $this->visits($leader, 2);
        $this->assertNull(TopReferrerStandings::leadersForMonth($business, '2026-10'));
        $this->visit($leader);
        $this->assertSame(3, TopReferrerStandings::leadersForMonth($business, '2026-10')['count']);
    }

    public function test_ties_crown_every_co_top(): void
    {
        $business = World::business();
        $a = $this->member($business, 'Ann');
        $b = $this->member($business, 'Bob');
        $c = $this->member($business, 'Cat');
        $this->visits($a, 4);
        $this->visits($b, 4);
        $this->visits($c, 3);

        $leaders = TopReferrerStandings::leadersForMonth($business, '2026-10');
        $this->assertSame('tie', $leaders['form']);
        $this->assertSame([$a->id, $b->id], array_map(fn ($m) => $m->id, $leaders['leaders']));
    }

    public function test_an_unsendable_leader_crowns_nobody_and_never_passes_to_second(): void
    {
        $business = World::business();
        $leader = $this->member($business);
        $second = $this->member($business);
        $this->visits($leader, 5);
        $this->visits($second, 4);

        SmsOptOut::create(['phone_e164' => $leader->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);

        $this->assertNull(TopReferrerStandings::leadersForMonth($business, '2026-10'));
    }

    public function test_the_month_buckets_in_business_local_time_not_utc(): void
    {
        $business = World::business(); // America/New_York
        $leader = $this->member($business);
        // Oct 31 23:30 New York = Nov 1 03:30 UTC: an October
        // visit for the shop, a November one for UTC.
        $this->visits($leader, 3, ['redeemed_at' => '2026-10-31 23:30:00']);
        // Nov 1 00:30 New York = still October in UTC terms
        // only if misread; it is a November visit locally.
        $this->visit($leader, ['redeemed_at' => '2026-11-01 00:30:00']);

        $this->assertSame(3, TopReferrerStandings::countsForMonth($business, '2026-10')[$leader->id] ?? 0);
        $this->assertSame(1, TopReferrerStandings::countsForMonth($business, '2026-11')[$leader->id] ?? 0);
    }

    public function test_the_window_is_days_1_to_14_for_the_previous_month(): void
    {
        $business = World::business();
        $this->assertSame('2026-10', TopReferrerStandings::currentStandingsMonth($business));

        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-15 09:00:00', 'America/New_York'));
        $this->assertNull(TopReferrerStandings::currentStandingsMonth($business->fresh()));

        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-14 23:59:00', 'America/New_York'));
        $this->assertSame('2026-10', TopReferrerStandings::currentStandingsMonth($business->fresh()));
    }
}
