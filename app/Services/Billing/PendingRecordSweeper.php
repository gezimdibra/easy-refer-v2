<?php

namespace App\Services\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The pending sweeper (spec Sections 9.3 rule 4 and 9.7,
 * blueprint Section 3.B): a billing_record stuck in `pending`
 * past the threshold is reconciled against Square and resolved
 * to a truthful state. If Square holds the charge, the record
 * adopts it; if Square definitively rejected it, the record
 * fails and its events are released; if Square has nothing for
 * the key, the events are released and the record is superseded
 * so the next run builds a fresh one. Simulated records are
 * never swept: they are dry runs, not charge attempts.
 */
class PendingRecordSweeper
{
    public function __construct(private SquarePaymentsGateway $gateway) {}

    /**
     * @return array{adopted: int, failed: int, superseded: int, still_pending: int}
     */
    public function sweep(int $olderThanMinutes = 15): array
    {
        $summary = ['adopted' => 0, 'failed' => 0, 'superseded' => 0, 'still_pending' => 0];

        $records = BillingRecord::query()
            ->where('state', BillingRecord::STATE_PENDING)
            ->where('created_at', '<=', now()->subMinutes($olderThanMinutes))
            ->orderBy('id')
            ->get();

        foreach ($records as $record) {
            try {
                $result = $this->gateway->resolveCharge($record);
            } catch (SquareOutcomeUnknown) {
                $summary['still_pending']++;
                continue;
            }

            if ($result === null) {
                DB::transaction(function () use ($record) {
                    BillingEvent::query()
                        ->where('billing_record_id', $record->id)
                        ->update(['billing_record_id' => null]);
                    $record->forceFill(['state' => BillingRecord::STATE_SUPERSEDED])->save();
                });
                $summary['superseded']++;
                continue;
            }

            if ($result->succeeded) {
                $record->forceFill([
                    'state' => BillingRecord::STATE_SUCCEEDED,
                    'square_payment_id' => $result->paymentId,
                    'square_order_id' => $result->orderId ?? $record->square_order_id,
                ])->save();
                $summary['adopted']++;
                continue;
            }

            DB::transaction(function () use ($record, $result) {
                BillingEvent::query()
                    ->where('billing_record_id', $record->id)
                    ->update(['billing_record_id' => null]);
                $record->forceFill([
                    'state' => BillingRecord::STATE_FAILED,
                    'error_code' => $result->errorCode,
                ])->save();
            });
            $summary['failed']++;
        }

        if ($summary !== ['adopted' => 0, 'failed' => 0, 'superseded' => 0, 'still_pending' => 0]) {
            Log::info('Pending billing sweeper resolved stale records.', $summary);
        }

        return $summary;
    }
}
