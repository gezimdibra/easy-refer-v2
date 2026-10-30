<?php

namespace App\Services\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\SmsCampaignRecipient;
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
 *
 * Release is symmetric across both legs of a batch (money fix
 * train, audit 2.3): the campaign recipient rows claimed under
 * the record are released with its billing events, exactly as
 * the charger's own definitive-failure path releases them. A
 * text row left claimed under a failed or superseded record
 * would never bill and never appear on any statement.
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
                    SmsCampaignRecipient::query()
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
                // FE-CANCEL-01: a resolved final-bill record on a
                // closed business sends its E-10 mail now (the
                // tap-time job sent nothing while it was pending).
                app(FinalBillService::class)->notifyResolved($record);
                continue;
            }

            DB::transaction(function () use ($record, $result) {
                BillingEvent::query()
                    ->where('billing_record_id', $record->id)
                    ->update(['billing_record_id' => null]);
                SmsCampaignRecipient::query()
                    ->where('billing_record_id', $record->id)
                    ->update(['billing_record_id' => null]);
                $record->forceFill([
                    'state' => BillingRecord::STATE_FAILED,
                    'error_code' => $result->errorCode,
                ])->save();
            });
            // FE-CANCEL-01: same E-10 resolution hook as the
            // adopted branch, in the failed form.
            app(FinalBillService::class)->notifyResolved($record);
            $summary['failed']++;
        }

        if ($summary !== ['adopted' => 0, 'failed' => 0, 'superseded' => 0, 'still_pending' => 0]) {
            Log::info('Pending billing sweeper resolved stale records.', $summary);
        }

        return $summary;
    }
}
