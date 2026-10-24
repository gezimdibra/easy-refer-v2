<?php

namespace App\Services\Billing;

use App\Models\BillingEvent;
use App\Models\Business;
use Carbon\Carbon;

/**
 * The Value panel's arithmetic (spec Sections 5.9 and 9.5).
 * Verified visits are billing event rows; estimated first-visit
 * sales are visits times the business's average ticket; platform
 * fees are what those visits carried under the plan's money rules,
 * derived by one settlement walk that the Phase 4 nightly batch
 * also uses to persist coverage: free-visit credit first, in event
 * creation order (the first free allowance of events a business
 * ever records), then bills in order until the next charge would
 * cross the monthly cents cap, so a month tops out at 49 billed
 * visits ($98) and never crosses $99 (OD-1). Because the dashboard
 * and the batch share this walk, the figures reconcile exactly; a
 * figure that cannot reconcile does not render.
 */
class VisitValuation
{
    /**
     * Settlements computed by this instance, keyed by business
     * id. The dashboard asks for the same settlement twice (the
     * month panel and the all-time panel), so the walk runs once
     * per business per resolution instead of once per panel.
     *
     * A memoized settlement stays valid only while the caller
     * writes no billing rows for that business on this instance.
     * A caller that settles and then records or changes billing
     * events within the same resolution (the shape the Phase 4
     * nightly batch takes when it persists coverage) MUST call
     * forgetSettlements() after writing, or resolve a fresh
     * instance; otherwise the next settle() returns the stale
     * settlement.
     *
     * @var array<int, array{verified_visits: int, estimated_sales_cents: int, fees_cents: int, settled: array<int, array{coverage: string, fee_cents: int}>}>
     */
    private array $settledByBusiness = [];

    /**
     * Settle a business's events into coverage and fees.
     *
     * The result is memoized on this instance; see the
     * $settledByBusiness contract for when that stays valid and
     * forgetSettlements() for the invalidation path.
     *
     * @return array{verified_visits: int, estimated_sales_cents: int, fees_cents: int, settled: array<int, array{coverage: string, fee_cents: int}>}
     */
    public function settle(Business $business): array
    {
        return $this->settledByBusiness[$business->id] ??= $this->computeSettlement($business);
    }

    /**
     * Drop every settlement memoized on this instance, so the
     * next settle() call walks the billing rows again. Callers
     * that write billing events after settling on the same
     * instance (the Phase 4 nightly batch persisting coverage
     * is the expected one) must call this once those writes
     * land.
     */
    public function forgetSettlements(): void
    {
        $this->settledByBusiness = [];
    }

    /**
     * @return array{verified_visits: int, estimated_sales_cents: int, fees_cents: int, settled: array<int, array{coverage: string, fee_cents: int}>}
     */
    private function computeSettlement(Business $business): array
    {
        $events = BillingEvent::query()
            ->where('business_id', $business->id)
            ->orderBy('id')
            ->get();

        $plan = $business->currentPlan;
        // The first-visits-free credit is the one-time allowance
        // on the business row (Section 7.2/9.5), never a plan
        // field: the plan row's included_referrals_per_period is
        // NULL by the seed contract.
        $freeAllowance = (int) $business->free_visits_remaining;
        $cap = $plan !== null ? (int) ($plan->monthly_cap_cents ?? PHP_INT_MAX) : PHP_INT_MAX;

        $settled = [];
        $fees = 0;
        $freeUsed = 0;
        $billedThisMonth = [];
        $currentMonth = null;
        $monthBilledCents = 0;

        foreach ($events as $event) {
            // created_at is cast to a Carbon instance on the
            // model; formatting it directly keeps this walk cheap
            // at five-figure event counts (re-parsing the instance
            // through Carbon::parse cost ~50us a row, Sprint 6).
            $month = $event->created_at->format('Y-m');

            if ($month !== $currentMonth) {
                $currentMonth = $month;
                $monthBilledCents = 0;
            }

            $amount = (int) $event->amount_cents;

            if ($freeUsed < $freeAllowance) {
                $settled[$event->id] = ['coverage' => 'free_credit', 'fee_cents' => 0];
                $freeUsed++;
            } elseif ($monthBilledCents + $amount > $cap) {
                // The next charge would cross the cap: this visit
                // is recorded free (Section 9.5).
                $settled[$event->id] = ['coverage' => 'over_cap', 'fee_cents' => 0];
            } else {
                $settled[$event->id] = ['coverage' => 'billable', 'fee_cents' => $amount];
                $monthBilledCents += $amount;
                $fees += $amount;
            }

            $billedThisMonth[$month] = $monthBilledCents;
        }

        return [
            'verified_visits' => $events->count(),
            'estimated_sales_cents' => $events->count() * (int) $business->average_ticket_cents,
            'fees_cents' => $fees,
            'settled' => $settled,
        ];
    }

    /**
     * @return array{verified_visits: int, estimated_sales_cents: int, fees_cents: int}
     */
    public function forMonth(Business $business, Carbon $month): array
    {
        $settled = $this->settle($business);

        $events = BillingEvent::query()
            ->where('business_id', $business->id)
            ->whereBetween('created_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->orderBy('id')
            ->get();

        $fees = 0;
        foreach ($events as $event) {
            $fees += $settled['settled'][$event->id]['fee_cents'] ?? 0;
        }

        return [
            'verified_visits' => $events->count(),
            'estimated_sales_cents' => $events->count() * (int) $business->average_ticket_cents,
            'fees_cents' => $fees,
        ];
    }
}
