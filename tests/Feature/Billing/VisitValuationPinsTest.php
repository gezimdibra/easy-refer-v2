<?php

namespace Tests\Feature\Billing;

use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Membership;
use App\Services\Billing\VisitValuation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Service-level pins for the settlement walk (Phase 4 coverage
 * map rows 1 to 4): the free-visit credit is lifetime and consumed
 * in creation order across month boundaries, and the monthly cap
 * is a cents rule (an event landing exactly ON the cap is billable;
 * the walk stops before any event that would cross it), resetting
 * each calendar month. The dashboard and the Phase 4 batch share
 * this walk, so these numbers are the charge numbers.
 */
class VisitValuationPinsTest extends TestCase
{
    use RefreshDatabase;

    private int $friendSequence = 2000;

    private function membership(Business $business): Membership
    {
        $referrer = World::customer('+12125550998', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);

        return $membership;
    }

    private function event(Business $business, Membership $membership, int $amountCents, Carbon $at): BillingEvent
    {
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);

        $event = BillingEvent::create([
            'business_id' => $business->id,
            'referral_id' => $referral->id,
            'amount_cents' => $amountCents,
        ]);
        $event->forceFill(['created_at' => $at])->save();

        return $event;
    }

    /**
     * @param  list<BillingEvent>  $events
     * @return list<string>
     */
    private function coverages(array $settled, array $events): array
    {
        return array_map(fn (BillingEvent $e) => $settled['settled'][$e->id]['coverage'], $events);
    }

    public function test_free_allowance_is_lifetime_and_spans_a_month_boundary(): void
    {
        $business = World::business(['free_visits_remaining' => 5]);
        $membership = $this->membership($business);

        $january = [];
        for ($i = 0; $i < 3; $i++) {
            $january[] = $this->event($business, $membership, 200, Carbon::parse('2026-01-10 12:00:00'));
        }
        $february = [];
        for ($i = 0; $i < 4; $i++) {
            $february[] = $this->event($business, $membership, 200, Carbon::parse('2026-02-10 12:00:00'));
        }

        $valuation = app(VisitValuation::class);
        $settled = $valuation->settle($business);

        // The credit is one lifetime allowance in creation order:
        // all 3 January events and the first 2 February events draw
        // it, even though February is a new month.
        $this->assertSame(['free_credit', 'free_credit', 'free_credit'], $this->coverages($settled, $january));
        $this->assertSame(['free_credit', 'free_credit', 'billable', 'billable'], $this->coverages($settled, $february));
        $this->assertSame(400, $settled['fees_cents']);
        $this->assertSame(0, $valuation->forMonth($business, Carbon::parse('2026-01-01'))['fees_cents']);
        $this->assertSame(400, $valuation->forMonth($business, Carbon::parse('2026-02-01'))['fees_cents']);
    }

    public function test_partially_pre_consumed_allowance_covers_only_what_remains(): void
    {
        $business = World::business(['free_visits_remaining' => 3]);
        $membership = $this->membership($business);

        $events = [];
        for ($i = 0; $i < 5; $i++) {
            $events[] = $this->event($business, $membership, 200, Carbon::parse('2026-03-10 12:00:00'));
        }

        $settled = app(VisitValuation::class)->settle($business);

        $this->assertSame(
            ['free_credit', 'free_credit', 'free_credit', 'billable', 'billable'],
            $this->coverages($settled, $events),
        );
        $this->assertSame(400, $settled['fees_cents']);
    }

    public function test_zero_allowance_business_bills_from_the_first_visit(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);

        $events = [];
        for ($i = 0; $i < 2; $i++) {
            $events[] = $this->event($business, $membership, 200, Carbon::parse('2026-03-10 12:00:00'));
        }

        $settled = app(VisitValuation::class)->settle($business);

        $this->assertSame(['billable', 'billable'], $this->coverages($settled, $events));
        $this->assertSame(400, $settled['fees_cents']);
    }

    public function test_allowance_larger_than_the_event_count_covers_everything(): void
    {
        $business = World::business(['free_visits_remaining' => 5]);
        $membership = $this->membership($business);

        $events = [];
        for ($i = 0; $i < 2; $i++) {
            $events[] = $this->event($business, $membership, 200, Carbon::parse('2026-03-10 12:00:00'));
        }

        $settled = app(VisitValuation::class)->settle($business);

        $this->assertSame(['free_credit', 'free_credit'], $this->coverages($settled, $events));
        $this->assertSame(0, $settled['fees_cents']);
    }

    public function test_cap_tail_is_labeled_per_event_at_the_boundary(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);

        $events = [];
        for ($i = 0; $i < 55; $i++) {
            $events[] = $this->event($business, $membership, 200, Carbon::parse('2026-03-10 12:00:00'));
        }

        $settled = app(VisitValuation::class)->settle($business);
        $coverages = $this->coverages($settled, $events);

        // The 49th billed visit lands at 9,800 cents; the 50th
        // event onward is over_cap with a zero fee, per event, not
        // just in the total.
        $this->assertSame(array_fill(0, 49, 'billable'), array_slice($coverages, 0, 49));
        $this->assertSame(array_fill(0, 6, 'over_cap'), array_slice($coverages, 49));
        $this->assertSame(200, $settled['settled'][$events[48]->id]['fee_cents']);
        $this->assertSame(0, $settled['settled'][$events[49]->id]['fee_cents']);
        $this->assertSame(9800, $settled['fees_cents']);
    }

    public function test_an_event_landing_exactly_on_the_cap_is_billable(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);

        $events = [];
        for ($i = 0; $i < 49; $i++) {
            $events[] = $this->event($business, $membership, 200, Carbon::parse('2026-03-10 12:00:00'));
        }
        // 49 x 200 = 9,800; a 100-cent event lands exactly on the
        // 9,900 cap and is billable (the rule is strictly greater).
        $events[] = $this->event($business, $membership, 100, Carbon::parse('2026-03-10 12:00:00'));
        $events[] = $this->event($business, $membership, 100, Carbon::parse('2026-03-10 12:00:00'));

        $settled = app(VisitValuation::class)->settle($business);
        $coverages = $this->coverages($settled, $events);

        $this->assertSame('billable', $coverages[49]);
        $this->assertSame(100, $settled['settled'][$events[49]->id]['fee_cents']);
        $this->assertSame('over_cap', $coverages[50]);
        $this->assertSame(9900, $settled['fees_cents']);
    }

    public function test_cap_resets_at_a_calendar_month_boundary(): void
    {
        $business = World::business(['free_visits_remaining' => 0]);
        $membership = $this->membership($business);

        for ($i = 0; $i < 55; $i++) {
            $this->event($business, $membership, 200, Carbon::parse('2026-01-10 12:00:00'));
        }
        for ($i = 0; $i < 55; $i++) {
            $this->event($business, $membership, 200, Carbon::parse('2026-02-10 12:00:00'));
        }

        $valuation = app(VisitValuation::class);

        // Two months bill independently: each caps at 9,800.
        $this->assertSame(9800, $valuation->forMonth($business, Carbon::parse('2026-01-01'))['fees_cents']);
        $this->assertSame(9800, $valuation->forMonth($business, Carbon::parse('2026-02-01'))['fees_cents']);
        $this->assertSame(19600, $valuation->settle($business)['fees_cents']);
    }

    public function test_free_allowance_in_month_one_leaves_month_two_cap_arithmetic_unaffected(): void
    {
        $business = World::business(['free_visits_remaining' => 5]);
        $membership = $this->membership($business);

        for ($i = 0; $i < 5; $i++) {
            $this->event($business, $membership, 200, Carbon::parse('2026-01-10 12:00:00'));
        }
        $february = [];
        for ($i = 0; $i < 50; $i++) {
            $february[] = $this->event($business, $membership, 200, Carbon::parse('2026-02-10 12:00:00'));
        }

        $valuation = app(VisitValuation::class);
        $settled = $valuation->settle($business);

        $this->assertSame(0, $valuation->forMonth($business, Carbon::parse('2026-01-01'))['fees_cents']);
        $this->assertSame(9800, $valuation->forMonth($business, Carbon::parse('2026-02-01'))['fees_cents']);
        $febCoverages = $this->coverages($settled, $february);
        $this->assertSame('billable', $febCoverages[48]);
        $this->assertSame('over_cap', $febCoverages[49]);
    }

    public function test_for_month_reconciles_with_settle_across_three_months(): void
    {
        $business = World::business(['free_visits_remaining' => 5]);
        $membership = $this->membership($business);

        $byMonth = ['2026-01' => 10, '2026-02' => 60, '2026-03' => 7];
        $eventsByMonth = [];
        foreach ($byMonth as $month => $count) {
            $eventsByMonth[$month] = [];
            for ($i = 0; $i < $count; $i++) {
                $eventsByMonth[$month][] = $this->event($business, $membership, 200, Carbon::parse($month.'-10 12:00:00'));
            }
        }

        $valuation = app(VisitValuation::class);
        $settled = $valuation->settle($business);

        $totalFromMonths = 0;
        foreach (array_keys($byMonth) as $month) {
            $expected = 0;
            foreach ($eventsByMonth[$month] as $event) {
                $expected += $settled['settled'][$event->id]['fee_cents'];
            }
            $actual = $valuation->forMonth($business, Carbon::parse($month.'-01'))['fees_cents'];
            $this->assertSame($expected, $actual, "forMonth disagrees with settle() for {$month}");
            $totalFromMonths += $actual;
        }

        $this->assertSame($settled['fees_cents'], $totalFromMonths);
        // Jan: 5 free + 5 billable = 1,000. Feb: 49 billable, 11
        // over cap = 9,800. Mar: 7 billable = 1,400.
        $this->assertSame(12200, $settled['fees_cents']);
    }
}
