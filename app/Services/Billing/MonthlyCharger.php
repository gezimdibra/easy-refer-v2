<?php

namespace App\Services\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\BusinessAlert;
use App\Models\PaymentMethod;
use App\Models\SmsCampaignRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The monthly per-referral charge (spec Sections 9.5 and 9.3,
 * OD-1): assemble a business's unbilled billable events for ONE
 * month of visits (each month already cap-settled inside its
 * own month by the shared walk) and charge them once against
 * the card on file. Arrears settle month by month as separate
 * records, never one combined charge (money fix train, audit
 * 1.3): charge() takes the oldest unclaimed month, and
 * chargeAllMonths() drains several arrears months in one run
 * as one record per month. The only cross-month carry left is
 * the Square minimum: a month whose combined total is below
 * it rolls its rows into the next month's batch, exactly the
 * carry the spec names, and never more than those cents.
 *
 * The campaign leg (spec Sections 10.3, 9.5): attempted
 * campaign recipients whose sends were never claimed join the
 * same monthly payment as one "Text campaigns" line. The $99
 * cap binds the visit leg only (it is enforced inside the
 * settlement walk, before this class runs); texts are never
 * free, never bundled, and never offset by the free-visit
 * credit. A month with texts but no billable visits charges
 * through a campaign_metering record of its own. A recipient
 * whose send was never attempted (no sms_messages evidence
 * row) is never billed. The Square $1 minimum reads the
 * combined total of both legs: below it, everything carries
 * forward and nothing is claimed.
 *
 * Discipline, in order: settlement persists first (collection);
 * the local billing_record exists in `pending` with its
 * deterministic key and the events claimed under it BEFORE the
 * Square call; the call happens outside the transaction; the
 * outcome is written back truthfully. A referral batch claims
 * the settled coverage rows of the months it bills alongside
 * the billable events (FE-BILL-01), so the record's statement
 * accounts for every visit in its window; the coverage rows
 * never enter the charge total. A definitive Square
 * failure releases the events so a later run rebuilds under a
 * new record and key; an unknown outcome leaves the record
 * pending and the events claimed for the sweeper (EC-33). A
 * business with no default card is skipped with an owner alert
 * and its events stay unbilled-pending (Section 9.9): the debt
 * is recorded in the open, never silently written off.
 *
 * Record-only mode (billing.charges_enabled false, the state
 * until the Phase 6 launch approval): the would-be batch is
 * computed and stored as a `simulated` record that claims no
 * events and never reaches the gateway (spec Section 9.5,
 * BILL-17).
 */
class MonthlyCharger
{
    public function __construct(
        private SquarePaymentsGateway $gateway,
        private SettlementService $settlement,
    ) {}

    public function charge(Business $business): ?BillingRecord
    {
        // The demo business (Amendment 63, Section 13.6) is
        // never chargeable. BOTH exclusion legs live in this
        // surface (A-18 Finding 3): the flag leg here, before
        // any settlement or batch read, and the coverage leg
        // in nextBatch(), which never selects a coverage='demo'
        // event for any business. Neither leg is delegated to
        // the other surface.
        if ($business->isDemo()) {
            return null;
        }

        $this->settlement->settleAndPersist($business);

        $batch = $this->nextBatch($business);

        if ($batch === null) {
            return null;
        }

        return $this->chargeBatch($business, $batch['events'], $batch['sms'], $batch['total']);
    }

    /**
     * Drain every arrears month in one run, one record per
     * month of visits, oldest first. The drain stops at the
     * first record that does not succeed: a definitive failure
     * or an unknown outcome is one attempt per run against that
     * card, and a simulated (record-only) batch claims nothing,
     * so there is nothing further to drain.
     *
     * @return list<BillingRecord>
     */
    public function chargeAllMonths(Business $business): array
    {
        $records = [];

        while (true) {
            $record = $this->charge($business);

            if ($record === null) {
                break;
            }

            $records[] = $record;

            if ($record->state !== BillingRecord::STATE_SUCCEEDED) {
                break;
            }

            $business = $business->fresh() ?? $business;
        }

        return $records;
    }

    /**
     * The next chargeable batch: the oldest unclaimed month of
     * visits and texts, grouped by the month each row was
     * created in (the settlement walk's own month, UTC). A
     * month whose combined total sits below the Square minimum
     * carries its rows into the next month's batch; when every
     * remaining month together is still below the minimum,
     * nothing is claimed and nothing is charged.
     *
     * @return array{events: \Illuminate\Support\Collection<int, BillingEvent>, sms: \Illuminate\Support\Collection<int, SmsCampaignRecipient>, total: int}|null
     */
    private function nextBatch(Business $business): ?array
    {
        // Coverage leg of the demo double exclusion, in this
        // surface (A-18 Finding 3): a coverage='demo' event is
        // never selected for a batch, for any business. The
        // billable-only filter below already implies it; the
        // explicit condition keeps the leg visible and pinned
        // if the billable set ever widens.
        $events = BillingEvent::query()
            ->where('business_id', $business->id)
            ->whereNull('billing_record_id')
            ->where('coverage', '!=', 'demo')
            ->where('coverage', 'billable')
            ->orderBy('id')
            ->get();

        // The campaign leg: this business's attempted, unclaimed
        // recipient rows. Attempted is proven by the evidence
        // link; skipped and capped-out rows carry amounts but no
        // link and are never billed.
        $smsRows = SmsCampaignRecipient::query()
            ->whereHas('campaign', fn ($query) => $query->where('business_id', $business->id))
            ->whereNull('billing_record_id')
            ->whereNull('written_off_at')
            ->whereNotNull('sms_message_id')
            ->orderBy('id')
            ->get();

        if ($events->isEmpty() && $smsRows->isEmpty()) {
            return null;
        }

        $minimum = (int) config('billing.minimum_charge_cents', 100);

        $byMonth = [];
        foreach ($events as $event) {
            $byMonth[$event->created_at->format('Y-m')]['events'][] = $event;
        }
        foreach ($smsRows as $row) {
            $byMonth[$row->created_at->format('Y-m')]['sms'][] = $row;
        }
        ksort($byMonth);
        $months = array_keys($byMonth);
        $lastMonth = end($months);

        $carryEvents = collect();
        $carrySms = collect();

        foreach ($byMonth as $month => $group) {
            $candidateEvents = $carryEvents->concat($group['events'] ?? []);
            $candidateSms = $carrySms->concat($group['sms'] ?? []);
            $total = (int) $candidateEvents->sum('amount_cents') + (int) $candidateSms->sum('amount_cents');

            if ($total < $minimum && $month !== $lastMonth) {
                $carryEvents = $candidateEvents;
                $carrySms = $candidateSms;
                continue;
            }

            if ($total < $minimum) {
                Log::info('Billing batch carried forward below the Square minimum.', [
                    'business_id' => $business->id,
                    'carried_cents' => $total,
                ]);

                return null;
            }

            return ['events' => $candidateEvents, 'sms' => $candidateSms, 'total' => $total];
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BillingEvent>  $events
     * @param  \Illuminate\Support\Collection<int, SmsCampaignRecipient>  $smsRows
     */
    private function chargeBatch(Business $business, $events, $smsRows, int $total): ?BillingRecord
    {
        $visitTotal = (int) $events->sum('amount_cents');
        $smsTotal = (int) $smsRows->sum('amount_cents');
        $kind = $events->isNotEmpty()
            ? BillingRecord::KIND_PER_REFERRAL_BATCH
            : BillingRecord::KIND_CAMPAIGN_METERING;

        if (! (bool) config('billing.charges_enabled', false)) {
            return $this->recordDryRun($business, $kind, $events, $smsRows, $total);
        }

        $card = PaymentMethod::defaultFor($business);

        if ($card === null || $card->square_card_id === null) {
            BusinessAlert::firstOrCreate(
                ['business_id' => $business->id, 'kind' => 'billing_card_missing'],
                ['notification_state' => 'queued'],
            );

            Log::warning('Billing batch skipped: no card on file.', [
                'business_id' => $business->id,
                'accrued_cents' => $total,
            ]);

            return null;
        }

        // FE-BILL-01: a referral batch also claims the coverage
        // rows of the months it bills (free_credit and over_cap
        // events), so the per-charge statement can account for
        // every visit in its own window. The coverage rows
        // never enter the charge total — the total keeps
        // summing the billable collection only — and they are
        // claimed in the same transaction, so a definitive
        // failure releases them with everything else and a
        // later run re-claims them under the new record. The
        // scope is every unclaimed coverage row settled up to
        // the end of the batch's newest month: free/over-cap
        // coverage is final once persisted, and any earlier
        // batch already claimed its own months' rows, so the
        // remainder belongs to this batch's statement. A
        // campaign-only record claims no visit coverage rows:
        // its statement is a texts statement, not a visits
        // statement. Simulated records claim nothing (below).
        $coverageEvents = collect();
        if ($events->isNotEmpty()) {
            $newest = $this->periodEnd($events, $smsRows);
            $coverageEvents = BillingEvent::query()
                ->where('business_id', $business->id)
                ->whereNull('billing_record_id')
                ->whereIn('coverage', ['free_credit', 'over_cap'])
                ->where('created_at', '<=', $newest->copy()->endOfMonth())
                ->orderBy('id')
                ->get();
        }

        $record = DB::transaction(function () use ($business, $kind, $events, $smsRows, $total, $card, $coverageEvents) {
            // The period spans the claimed coverage rows too:
            // the statement window is the month the owner
            // lived, not just the first billed visit onward.
            $periodEvents = $events->concat($coverageEvents);

            $record = BillingRecord::create([
                'business_id' => $business->id,
                'kind' => $kind,
                'amount_cents' => $total,
                'currency' => 'USD',
                'idempotency_key' => $this->nextBatchKey($business, $kind),
                'payment_method_id' => $card->id,
                'state' => BillingRecord::STATE_PENDING,
                'period_start' => $this->periodStart($periodEvents, $smsRows),
                'period_end' => $this->periodEnd($periodEvents, $smsRows),
            ]);

            if ($events->isNotEmpty()) {
                BillingEvent::query()
                    ->whereIn('id', $events->pluck('id'))
                    ->update(['billing_record_id' => $record->id]);
            }

            if ($coverageEvents->isNotEmpty()) {
                BillingEvent::query()
                    ->whereIn('id', $coverageEvents->pluck('id'))
                    ->update(['billing_record_id' => $record->id]);
            }

            if ($smsRows->isNotEmpty()) {
                SmsCampaignRecipient::query()
                    ->whereIn('id', $smsRows->pluck('id'))
                    ->update(['billing_record_id' => $record->id]);
            }

            return $record;
        });
        $lineItems = $events->map(fn (BillingEvent $event) => [
            'name' => 'Referral fee',
            'note' => 'referral '.$event->referral_id,
            'amount_cents' => (int) $event->amount_cents,
        ])->all();

        if ($smsTotal > 0) {
            $lineItems[] = [
                'name' => 'Text campaigns',
                'note' => 'text campaigns',
                'amount_cents' => $smsTotal,
            ];
        }

        try {
            $result = $this->gateway->chargeCardOnFile($business, $card, $total, $record->idempotency_key, $lineItems);
        } catch (SquareOutcomeUnknown) {
            // The charge may exist at Square. The record stays
            // pending with the events claimed; the sweeper
            // resolves the truth from the persisted key.
            Log::warning('Billing batch charge outcome unknown; record left pending for the sweeper.', [
                'business_id' => $business->id,
                'billing_record_id' => $record->id,
            ]);

            return $record;
        }

        if ($result->succeeded) {
            $record->forceFill([
                'state' => BillingRecord::STATE_SUCCEEDED,
                'square_payment_id' => $result->paymentId,
                'square_order_id' => $result->orderId,
            ])->save();

            return $record;
        }

        // A definitive failure: Square created nothing, so the
        // events and recipient rows are released and a later run
        // rebuilds the batch under a new record with a new key.
        DB::transaction(function () use ($record, $result) {
            $record->forceFill([
                'state' => BillingRecord::STATE_FAILED,
                'error_code' => $result->errorCode,
            ])->save();

            BillingEvent::query()
                ->where('billing_record_id', $record->id)
                ->update(['billing_record_id' => null]);

            SmsCampaignRecipient::query()
                ->where('billing_record_id', $record->id)
                ->update(['billing_record_id' => null]);
        });

        return $record;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BillingEvent>  $events
     * @param  \Illuminate\Support\Collection<int, SmsCampaignRecipient>  $smsRows
     */
    private function recordDryRun(Business $business, string $kind, $events, $smsRows, int $total): BillingRecord
    {
        return DB::transaction(fn () => BillingRecord::create([
            'business_id' => $business->id,
            'kind' => $kind,
            'amount_cents' => $total,
            'currency' => 'USD',
            'idempotency_key' => $this->nextBatchKey($business, $kind),
            'state' => BillingRecord::STATE_SIMULATED,
            'period_start' => $this->periodStart($events, $smsRows),
            'period_end' => $this->periodEnd($events, $smsRows),
        ]));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BillingEvent>  $events
     * @param  \Illuminate\Support\Collection<int, SmsCampaignRecipient>  $smsRows
     */
    private function periodStart($events, $smsRows): mixed
    {
        return collect([$events->min('created_at'), $smsRows->min('created_at')])
            ->filter()
            ->min();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BillingEvent>  $events
     * @param  \Illuminate\Support\Collection<int, SmsCampaignRecipient>  $smsRows
     */
    private function periodEnd($events, $smsRows): mixed
    {
        return collect([$events->max('created_at'), $smsRows->max('created_at')])
            ->filter()
            ->max();
    }

    /**
     * The deterministic batch key (spec Section 9.3):
     * perref_{business_id}_{yyyy-mm-dd}_{batch_seq} for referral
     * batches, camp_{business_id}_{yyyy-mm-dd}_{batch_seq} for
     * campaign-metering records, where batch_seq is the persisted
     * per-business per-day counter, incremented atomically inside
     * the caller's transaction.
     */
    private function nextBatchKey(Business $business, string $kind = BillingRecord::KIND_PER_REFERRAL_BATCH): string
    {
        $today = now()->format('Y-m-d');

        DB::table('billing_batch_sequences')->upsert(
            [['business_id' => $business->id, 'batch_date' => $today, 'seq' => 1, 'created_at' => now(), 'updated_at' => now()]],
            ['business_id', 'batch_date'],
            ['seq' => DB::raw('seq + 1'), 'updated_at' => now()],
        );

        $seq = (int) DB::table('billing_batch_sequences')
            ->where('business_id', $business->id)
            ->where('batch_date', $today)
            ->value('seq');

        $prefix = $kind === BillingRecord::KIND_CAMPAIGN_METERING ? 'camp' : 'perref';

        return $prefix.'_'.$business->id.'_'.$today.'_'.$seq;
    }
}
