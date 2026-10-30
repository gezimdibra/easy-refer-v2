<?php

namespace App\Services\Billing;

use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\SmsCampaignRecipient;
use Carbon\Carbon;
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
     * A caller that already holds this request's settlement
     * (the dashboard's Value panel settles for its all-time
     * figures) passes it in, so the settlement — a full pass
     * over the business's billing events — runs once per page,
     * never twice. The figures are identical either way: this
     * method is their only source.
     *
     * @return array{accrued_cents: int, billable_events: int}
     */
    public function accruedPreview(Business $business, ?array $settlement = null): array
    {
        // Demo double exclusion (A-18 Finding 3): the flag leg
        // zeroes a demo business's preview here, and the
        // coverage leg lives in the walk this preview reads
        // (a coverage='demo' event never settles billable).
        if ($business->isDemo()) {
            return ['accrued_cents' => 0, 'billable_events' => 0];
        }

        $settlement ??= (new VisitValuation)->settle($business);

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
     * The forecast preview (Train A2, Amendment 37): the visit
     * leg from accruedPreview() PLUS the campaign text leg the
     * monthly charge collects in the same run. The text leg
     * mirrors MonthlyCharger::nextBatch()'s campaign leg
     * EXACTLY (this business's attempted, unclaimed recipient
     * rows, all unclaimed months: the forecast is the
     * all-months figure per the FIX-1 merge ruling), so the
     * forecast can never quote a text the charge will not
     * collect, nor miss one it will. accruedPreview() itself is
     * untouched: its visit-only semantics are load-bearing for
     * the arrears comparison and the fraud pins.
     *
     * @return array{billable_events: int, visit_cents: int, text_cents: int, total_cents: int}
     */
    public function forecastPreview(Business $business, ?array $settlement = null, ?int $textCents = null): array
    {
        // Demo double exclusion (A-18 Finding 3): a demo
        // business has NO forecast at all, both legs. The flag
        // leg zeroes it here (its text leg too: a demo
        // business sends no campaigns, and a stray row must
        // never quote one); the coverage leg is in the walk
        // accruedPreview reads.
        if ($business->isDemo()) {
            return ['billable_events' => 0, 'visit_cents' => 0, 'text_cents' => 0, 'total_cents' => 0];
        }

        $visit = $this->accruedPreview($business, $settlement);
        $text = $textCents ?? $this->unclaimedTextCents($business);

        return [
            'billable_events' => $visit['billable_events'],
            'visit_cents' => $visit['accrued_cents'],
            'text_cents' => $text,
            'total_cents' => $visit['accrued_cents'] + $text,
        ];
    }

    /**
     * Both text figures a billing surface needs, in ONE query:
     * the all-months unclaimed total (the forecast leg) and the
     * current (UTC) billing month's slice (the Value panel's
     * conditional line, scoped to the same month its visit
     * figure scopes to). One conditional-sum round trip keeps
     * the dashboard's measured query budget intact.
     *
     * @return array{all: int, month: int}
     */
    public function textFigures(Business $business, Carbon $month): array
    {
        $row = SmsCampaignRecipient::query()
            ->whereHas('campaign', fn ($query) => $query->where('business_id', $business->id))
            ->whereNull('billing_record_id')
            ->whereNull('written_off_at')
            ->whereNotNull('sms_message_id')
            ->selectRaw('SUM(amount_cents) as all_cents, SUM(CASE WHEN created_at >= ? AND created_at <= ? THEN amount_cents ELSE 0 END) as month_cents', [
                $month->copy()->startOfMonth()->toDateTimeString(),
                $month->copy()->endOfMonth()->toDateTimeString(),
            ])
            ->first();

        return [
            'all' => (int) ($row->all_cents ?? 0),
            'month' => (int) ($row->month_cents ?? 0),
        ];
    }

    /**
     * The accrued text charges inside one (UTC) billing month:
     * the Overview Value panel's conditional text line scopes
     * to the same month its visit figure scopes to.
     */
    public function textChargesForMonth(Business $business, Carbon $month): int
    {
        return (int) SmsCampaignRecipient::query()
            ->whereHas('campaign', fn ($query) => $query->where('business_id', $business->id))
            ->whereNull('billing_record_id')
            ->whereNull('written_off_at')
            ->whereNotNull('sms_message_id')
            ->whereBetween('created_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->sum('amount_cents');
    }

    /** The charger's campaign leg, as a forecast figure. */
    private function unclaimedTextCents(Business $business): int
    {
        return (int) SmsCampaignRecipient::query()
            ->whereHas('campaign', fn ($query) => $query->where('business_id', $business->id))
            ->whereNull('billing_record_id')
            ->whereNull('written_off_at')
            ->whereNotNull('sms_message_id')
            ->sum('amount_cents');
    }

    /**
     * The accrued preview scoped to one calendar month (money
     * fix train, audit 4.3): the billing page's This month
     * block counts the current month through forMonth, so its
     * built-up figure must read the same scope. accruedPreview
     * stays the all-months figure the forecast and the charge
     * run speak in; this variant answers for the one month the
     * block names, so the two scopes are never mixed on one
     * line without a label.
     *
     * @return array{accrued_cents: int, billable_events: int}
     */
    public function accruedPreviewForMonth(Business $business, Carbon $month, ?array $settlement = null): array
    {
        $settlement ??= (new VisitValuation)->settle($business);

        $accrued = 0;
        $count = 0;

        $unclaimed = BillingEvent::query()
            ->where('business_id', $business->id)
            ->whereNull('billing_record_id')
            ->whereBetween('created_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
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

            // FE-CANCEL-01 (allowance-once, design 4.3): if a
            // prior business under the same owner identity has
            // already consumed free credit, this business's
            // remaining allowance is spent before the walk runs,
            // so its counted visits settle billable from the
            // first one. Already-persisted free_credit events on
            // THIS business are ledger history and stand. The
            // check fails open (AllowanceOnce), and its evidence
            // is audited so a manual restore has a trail.
            if ((int) $locked->free_visits_remaining > 0) {
                $prior = (new AllowanceOnce)->priorConsumer($locked);

                if ($prior !== null) {
                    $locked->forceFill(['free_visits_remaining' => 0])->save();
                    \App\Models\AuditLog::write('billing.allowance_once_applied', null, [
                        'prior_business_id' => $prior->id,
                    ], $locked);
                }
            }

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
