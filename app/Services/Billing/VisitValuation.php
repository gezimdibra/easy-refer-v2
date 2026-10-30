<?php

namespace App\Services\Billing;

use App\Models\BillingEvent;
use App\Models\Business;
use Carbon\Carbon;

/**
 * The Value panel's arithmetic (spec Sections 5.9 and 9.5).
 * Verified visits are billing event rows; estimated sales are
 * visits times the business's typical spend per visit (stored
 * as average_ticket_cents); platform
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
     * The billing-event collections the settlement walks
     * loaded, keyed by business id (B7 M-4): forMonth() slices
     * the current month out of the walked collection instead
     * of re-querying the same rows. Cleared together with the
     * settlements by forgetSettlements(), so the two caches
     * can never disagree about freshness.
     *
     * @var array<int, \Illuminate\Database\Eloquent\Collection<int, BillingEvent>>
     */
    private array $eventsByBusiness = [];

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
        $this->eventsByBusiness = [];
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

        // Flag leg of the demo double exclusion, in this walk
        // (A-18 Finding 3): a business flagged demo settles
        // EVERY event as coverage='demo' at $0, whatever stamp
        // the event itself carries, so a stamp mismatch event
        // on the demo business is never promoted through the
        // free, billable or over-cap logic below, counts as no
        // verified visit, and estimates no sales. The coverage
        // leg below handles the mirror mismatch (a
        // coverage='demo' event on a real business).
        if ($business->isDemo()) {
            $settled = [];

            foreach ($events as $event) {
                $settled[$event->id] = ['coverage' => 'demo', 'fee_cents' => 0];
            }

            $this->eventsByBusiness[$business->id] = $events;

            return [
                'verified_visits' => 0,
                'estimated_sales_cents' => 0,
                'fees_cents' => 0,
                'settled' => $settled,
            ];
        }
        $this->eventsByBusiness[$business->id] = $events;

        $plan = $business->currentPlan;
        // The first-visits-free credit is the one-time allowance
        // on the business row (Section 7.2/9.5), never a plan
        // field: the plan row's included_referrals_per_period is
        // NULL by the seed contract. The Phase 4 batch decrements
        // free_visits_remaining atomically as it persists
        // free_credit coverage (Section 9.5), so the allowance
        // this walk may still hand out is the credit already
        // persisted on events plus the credit remaining on the
        // row; the two sum to the original allowance, which keeps
        // this walk's output identical before and after the batch
        // persists it (the dashboard and the batch reconcile
        // exactly, or the figure does not render).
        // B7 M-3: the walk already holds every event, coverage
        // column included; the persisted free-credit count is
        // counted from that collection, not queried again.
        $persistedFree = $events->where('coverage', 'free_credit')->count();
        $freeAllowance = $persistedFree + (int) $business->free_visits_remaining;
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

            if ($event->coverage === 'demo') {
                // A demo visit (Amendment 63): it is not a
                // verified visit for money purposes. It carries
                // no fee, consumes no free credit and no cap
                // room, and the walk never promotes it. This
                // is the coverage leg in this walk; the flag
                // leg above settles a flagged business's every
                // event the same way, whatever its stamp.
                $settled[$event->id] = ['coverage' => 'demo', 'fee_cents' => 0];
            } elseif ($event->coverage === 'fraud_denied') {
                // A visit whose reward was denied as fraud in
                // review (money fix train, audit 1.3): it is not
                // billable, it consumes no free credit and no
                // cap room, and the walk never promotes it back.
                $settled[$event->id] = ['coverage' => 'fraud_denied', 'fee_cents' => 0];
            } elseif ($event->coverage === 'written_off') {
                // FE-CANCEL-01: a sub-$1 residual written off
                // when the program ended. Terminal: no fee, no
                // free credit, no cap room, never promoted back.
                $settled[$event->id] = ['coverage' => 'written_off', 'fee_cents' => 0];
            } elseif ($event->coverage === 'held') {
                // A visit minted under an anomaly hold (money
                // fix train, audit 3.4): it settles only when
                // the review releases the reward. Until then it
                // consumes no free credit and no cap room, so a
                // later visit is never billed in its place by
                // accident of ordering.
                $settled[$event->id] = ['coverage' => 'held', 'fee_cents' => 0];
            } elseif ($event->coverage === 'refunded') {
                // A refunded visit is terminal (Section 9.8): it
                // carries no fee, but it did consume its month's
                // cap slot when it was charged, so the cap walk
                // still counts it; a refund never retroactively
                // promotes an over-cap visit into a charge.
                $settled[$event->id] = ['coverage' => 'refunded', 'fee_cents' => 0];
                $monthBilledCents += $amount;
            } elseif ($freeUsed < $freeAllowance) {
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

        // B7 M-4: the settlement walk above already loaded
        // every event for this business (created_at included,
        // in id order); the month slice is filtered out of
        // that collection with the same bounds the query used.
        // A cold instance whose walk somehow left no retained
        // collection falls back to the query unchanged.
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $walked = $this->eventsByBusiness[$business->id] ?? null;
        $events = $walked !== null
            ? $walked->filter(fn (BillingEvent $event) => $event->created_at->between($monthStart, $monthEnd))->values()
            : BillingEvent::query()
                ->where('business_id', $business->id)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
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
