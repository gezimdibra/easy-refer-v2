<?php

namespace App\Services\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\BusinessAlert;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The monthly per-referral charge (spec Sections 9.5 and 9.3,
 * OD-1): assemble a business's unbilled billable events (this
 * month's plus any carried forward from earlier months, each
 * already cap-settled inside its own month by the shared walk),
 * and charge them once against the card on file.
 *
 * Discipline, in order: settlement persists first (collection);
 * the local billing_record exists in `pending` with its
 * deterministic key and the events claimed under it BEFORE the
 * Square call; the call happens outside the transaction; the
 * outcome is written back truthfully. A definitive Square
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
        $this->settlement->settleAndPersist($business);

        $events = BillingEvent::query()
            ->where('business_id', $business->id)
            ->whereNull('billing_record_id')
            ->where('coverage', 'billable')
            ->orderBy('id')
            ->get();

        if ($events->isEmpty()) {
            return null;
        }

        $total = (int) $events->sum('amount_cents');
        $minimum = (int) config('billing.minimum_charge_cents', 100);

        if ($total < $minimum) {
            // Sub-minimum totals carry forward into the next
            // month's batch (spec Section 9.5); nothing is
            // claimed and nothing is charged.
            Log::info('Billing batch carried forward below the Square minimum.', [
                'business_id' => $business->id,
                'carried_cents' => $total,
            ]);

            return null;
        }

        if (! (bool) config('billing.charges_enabled', false)) {
            return $this->recordDryRun($business, $events, $total);
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

        $record = DB::transaction(function () use ($business, $events, $total, $card) {
            $record = BillingRecord::create([
                'business_id' => $business->id,
                'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
                'amount_cents' => $total,
                'currency' => 'USD',
                'idempotency_key' => $this->nextBatchKey($business),
                'payment_method_id' => $card->id,
                'state' => BillingRecord::STATE_PENDING,
                'period_start' => $events->min('created_at'),
                'period_end' => $events->max('created_at'),
            ]);

            BillingEvent::query()
                ->whereIn('id', $events->pluck('id'))
                ->update(['billing_record_id' => $record->id]);

            return $record;
        });

        $lineItems = $events->map(fn (BillingEvent $event) => [
            'name' => 'Referral fee',
            'note' => 'referral '.$event->referral_id,
            'amount_cents' => (int) $event->amount_cents,
        ])->all();

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
        // events are released and a later run rebuilds the batch
        // under a new record with a new key.
        DB::transaction(function () use ($record, $result) {
            $record->forceFill([
                'state' => BillingRecord::STATE_FAILED,
                'error_code' => $result->errorCode,
            ])->save();

            BillingEvent::query()
                ->where('billing_record_id', $record->id)
                ->update(['billing_record_id' => null]);
        });

        return $record;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BillingEvent>  $events
     */
    private function recordDryRun(Business $business, $events, int $total): BillingRecord
    {
        return DB::transaction(fn () => BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'amount_cents' => $total,
            'currency' => 'USD',
            'idempotency_key' => $this->nextBatchKey($business),
            'state' => BillingRecord::STATE_SIMULATED,
            'period_start' => $events->min('created_at'),
            'period_end' => $events->max('created_at'),
        ]));
    }

    /**
     * The deterministic batch key (spec Section 9.3):
     * perref_{business_id}_{yyyy-mm-dd}_{batch_seq}, where
     * batch_seq is the persisted per-business per-day counter,
     * incremented atomically inside the caller's transaction.
     */
    private function nextBatchKey(Business $business): string
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

        return 'perref_'.$business->id.'_'.$today.'_'.$seq;
    }
}
