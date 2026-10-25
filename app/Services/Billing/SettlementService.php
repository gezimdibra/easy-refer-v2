<?php

namespace App\Services\Billing;

use App\Models\BillingEvent;
use App\Models\Business;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The nightly collection step (spec Section 9.5, BILL-13): the
 * settlement walk's outcomes are persisted onto the billing
 * events, and the free-visit credit is consumed for real.
 *
 * The walk itself (VisitValuation) is shared with the dashboard;
 * this service is its only persisting caller. It runs under a
 * row lock on the business, decrements free_visits_remaining
 * atomically per newly covered event (Section 7.4 rule 1: a
 * guarded single-statement decrement that applies only while
 * credit remains), and never touches events already billed under
 * a record or marked refunded beyond what the walk itself
 * returns for them. Charge assembly is MonthlyCharger's job;
 * collection alone never moves money.
 */
class SettlementService
{
    /**
     * The accrued, not-yet-charged figure for the billing page
     * (billing UI spec Section 2, Block D): the settlement
     * walk's billable outcomes over the events no record has
     * claimed yet. This is a preview only: it persists nothing,
     * claims nothing, and never runs the charger, so rendering
     * a page can never move money or rewrite coverage. The
     * walk is the same one settleAndPersist() persists, so the
     * preview and the eventual batch agree by construction.
     *
     * @return array{accrued_cents: int, billable_events: int}
     */
    public function accruedPreview(Business $business): array
    {
        $settlement = (new VisitValuation)->settle($business);

        $accrued = 0;
        $count = 0;

        $unclaimed = BillingEvent::query()
            ->where('business_id', $business->id)
            ->whereNull('billing_record_id')
            ->pluck('id');

        foreach ($unclaimed as $eventId) {
            $outcome = $settlement['settled'][$eventId] ?? null;

            if ($outcome !== null && $outcome['coverage'] === 'billable') {
                $accrued += $outcome['fee_cents'];
                $count++;
            }
        }

        return ['accrued_cents' => $accrued, 'billable_events' => $count];
    }

    /**
     * Settle one business and persist the coverage outcomes.
     *
     * @return array{verified_visits: int, estimated_sales_cents: int, fees_cents: int, settled: array<int, array{coverage: string, fee_cents: int}>}
     */
    public function settleAndPersist(Business $business): array
    {
        return DB::transaction(function () use ($business) {
            $locked = Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();

            $freeBefore = BillingEvent::query()
                ->where('business_id', $locked->id)
                ->where('coverage', 'free_credit')
                ->count();

            $settlement = (new VisitValuation)->settle($locked);

            // Events already billed under a settled record are
            // ledger history: their coverage is never rewritten,
            // whatever a later walk computes for them.
            $settledRecordIds = DB::table('billing_records')
                ->where('business_id', $locked->id)
                ->whereIn('state', ['succeeded', 'refunded', 'partially_refunded', 'disputed'])
                ->pluck('id');

            foreach ($settlement['settled'] as $eventId => $outcome) {
                BillingEvent::query()
                    ->whereKey($eventId)
                    ->where('coverage', '!=', $outcome['coverage'])
                    ->where(function ($query) use ($settledRecordIds) {
                        $query->whereNull('billing_record_id')
                            ->orWhereNotIn('billing_record_id', $settledRecordIds);
                    })
                    ->update(['coverage' => $outcome['coverage']]);
            }

            $freeAfter = 0;
            foreach ($settlement['settled'] as $outcome) {
                if ($outcome['coverage'] === 'free_credit') {
                    $freeAfter++;
                }
            }

            // Consume the credit the walk just handed out, one
            // guarded decrement per event, so the balance can
            // never go negative even if two collections race.
            for ($i = $freeBefore; $i < $freeAfter; $i++) {
                $decremented = Business::query()
                    ->whereKey($locked->id)
                    ->where('free_visits_remaining', '>', 0)
                    ->decrement('free_visits_remaining');

                if ($decremented !== 1) {
                    throw new RuntimeException(
                        'Free-visit credit underflow while settling business '.$locked->id,
                    );
                }
            }

            return $settlement;
        });
    }
}
