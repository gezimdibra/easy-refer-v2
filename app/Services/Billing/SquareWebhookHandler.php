<?php

namespace App\Services\Billing;

use App\Models\AuditLog;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\BillingRefund;
use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The state half of the Square webhook contract (spec Sections
 * 9.4, 9.6, 9.8; billing UI spec Section 4): the endpoint
 * verifies and records; this handler applies what a verified
 * event means. Handlers are state-monotonic (EC-28, EC-30): an
 * event never moves a record backwards, and terminal states are
 * absorbing.
 *
 * Matching is by Square IDs stored locally, never by email
 * (spec 9.4): payments match billing_records.square_payment_id;
 * a payment whose ID is unknown locally (a charge whose outcome
 * never reached us) matches its business by
 * businesses.square_customer_id, and resolves that business's
 * single pending record when there is exactly one. A verified
 * event that matches nothing is logged Critical, never silently
 * dropped: local and provider state have diverged and the
 * Section 9.7 reconciliation must explain it.
 *
 * Charge outcomes drive businesses.billing_status: a failed
 * charge sets past_due (this is the only writer of that value;
 * the billing center's banner keys on it), a successful charge
 * for a past_due business restores active. Each transition
 * writes an audit row. Refund rows and their effects are owned
 * by RefundService; disputes move the record only.
 */
class SquareWebhookHandler
{
    public function __construct(private RefundService $refunds) {}

    /**
     * @param  array<string, mixed>  $event  the decoded, verified delivery
     */
    public function handle(array $event): void
    {
        $type = (string) ($event['type'] ?? '');
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];

        match ($type) {
            'payment.created', 'payment.updated' => $this->handlePayment(is_array($object['payment'] ?? null) ? $object['payment'] : []),
            'refund.created', 'refund.updated' => $this->refunds->recordRefundFromWebhook(is_array($object['refund'] ?? null) ? $object['refund'] : []),
            'dispute.created' => $this->handleDisputeOpened(is_array($object['dispute'] ?? null) ? $object['dispute'] : []),
            // Square's current name for the dispute update at
            // the pinned API version (the spec's dispute.updated
            // is refused for new subscriptions; BU-8b evidence).
            'dispute.updated', 'dispute.state.changed' => $this->handleDisputeUpdated(is_array($object['dispute'] ?? null) ? $object['dispute'] : []),
            default => null,
        };
    }

    /** @param array<string, mixed> $payment */
    private function handlePayment(array $payment): void
    {
        $paymentId = (string) ($payment['id'] ?? '');
        $status = (string) ($payment['status'] ?? '');

        if ($paymentId === '' || $status === '') {
            return;
        }

        $record = BillingRecord::query()->where('square_payment_id', $paymentId)->first();
        $business = $record?->business;

        if ($business === null) {
            $customerId = (string) ($payment['customer_id'] ?? '');
            $business = $customerId !== ''
                ? Business::query()->where('square_customer_id', $customerId)->first()
                : null;

            if ($business !== null && $record === null) {
                $pending = BillingRecord::query()
                    ->where('business_id', $business->id)
                    ->where('state', BillingRecord::STATE_PENDING)
                    ->orderBy('id')
                    ->get();

                if ($pending->count() === 1) {
                    $record = $pending->first();
                }
            }
        }

        if ($business === null) {
            Log::critical('Square webhook payment names no local business or record; reconciliation must explain the divergence.', [
                'square_payment_id' => $paymentId,
                'status' => $status,
            ]);

            return;
        }

        if (in_array($status, ['COMPLETED', 'APPROVED'], true)) {
            DB::transaction(function () use ($record, $business, $payment, $paymentId) {
                if ($record !== null && $record->state === BillingRecord::STATE_PENDING) {
                    $record->forceFill([
                        'state' => BillingRecord::STATE_SUCCEEDED,
                        'square_payment_id' => $record->square_payment_id ?? $paymentId,
                        'square_order_id' => $record->square_order_id ?? (isset($payment['order_id']) ? (string) $payment['order_id'] : null),
                    ])->save();
                }

                if ($business->billing_status === 'past_due' && $record !== null) {
                    $business->forceFill(['billing_status' => 'active'])->save();
                    AuditLog::write('billing.reactivated', null, ['square_payment_id' => $paymentId], $business, $record);
                }
            });

            return;
        }

        if (in_array($status, ['FAILED', 'CANCELED'], true)) {
            DB::transaction(function () use ($record, $business, $paymentId, $status) {
                if ($record !== null && $record->state === BillingRecord::STATE_PENDING) {
                    $record->forceFill([
                        'state' => BillingRecord::STATE_FAILED,
                        'square_payment_id' => $record->square_payment_id ?? $paymentId,
                        'error_code' => $status === 'CANCELED' ? 'payment_canceled' : 'payment_failed',
                    ])->save();

                    // The charger's definitive-failure
                    // semantics: the events are released so a
                    // later run rebuilds the batch.
                    BillingEvent::query()
                        ->where('billing_record_id', $record->id)
                        ->update(['billing_record_id' => null]);
                }

                if ($business->billing_status === 'active') {
                    $business->forceFill(['billing_status' => 'past_due'])->save();
                    AuditLog::write('billing.past_due', null, ['square_payment_id' => $paymentId], $business, $record);
                }
            });
        }
    }

    /** @param array<string, mixed> $dispute */
    private function handleDisputeOpened(array $dispute): void
    {
        $record = $this->recordForDispute($dispute);

        if ($record === null) {
            return;
        }

        if (! in_array($record->state, [BillingRecord::STATE_SUCCEEDED, BillingRecord::STATE_PARTIALLY_REFUNDED], true)) {
            return;
        }

        DB::transaction(function () use ($record, $dispute) {
            $record->forceFill(['state' => BillingRecord::STATE_DISPUTED])->save();
            AuditLog::write('billing.dispute_opened', null, [
                'square_dispute_id' => (string) ($dispute['id'] ?? ''),
                'amount_cents' => (int) ($dispute['amount_money']['amount'] ?? 0),
            ], $record->business, $record);
        });
    }

    /** @param array<string, mixed> $dispute */
    private function handleDisputeUpdated(array $dispute): void
    {
        $record = $this->recordForDispute($dispute);

        if ($record === null || $record->state !== BillingRecord::STATE_DISPUTED) {
            return;
        }

        $status = strtoupper((string) ($dispute['status'] ?? ''));

        if ($status === 'WON') {
            DB::transaction(function () use ($record, $dispute) {
                $refunded = (int) $record->refunds()
                    ->where('state', BillingRefund::STATE_SUCCEEDED)
                    ->sum('amount_cents');
                $record->forceFill([
                    'state' => $refunded > 0
                        ? BillingRecord::STATE_PARTIALLY_REFUNDED
                        : BillingRecord::STATE_SUCCEEDED,
                ])->save();
                AuditLog::write('billing.dispute_won', null, [
                    'square_dispute_id' => (string) ($dispute['id'] ?? ''),
                ], $record->business, $record);
            });

            return;
        }

        if (in_array($status, ['LOST', 'ACCEPTED'], true)) {
            DB::transaction(function () use ($record, $dispute) {
                // Section 9.8: a lost dispute runs the same
                // clawback path as a refund for the attached
                // visits; the debit itself is Square's, so no
                // billing_refunds row is created.
                $this->refunds->clawbackAllVisits($record, 'webhook', 'dispute_lost');
                $record->forceFill(['state' => BillingRecord::STATE_REFUNDED])->save();
                AuditLog::write('billing.dispute_lost', null, [
                    'square_dispute_id' => (string) ($dispute['id'] ?? ''),
                ], $record->business, $record);
            });
        }
    }

    /** @param array<string, mixed> $dispute */
    private function recordForDispute(array $dispute): ?BillingRecord
    {
        $paymentId = (string) ($dispute['disputed_payment']['payment_id'] ?? $dispute['payment_id'] ?? '');

        if ($paymentId === '') {
            return null;
        }

        $record = BillingRecord::query()->where('square_payment_id', $paymentId)->first();

        if ($record === null) {
            Log::critical('Square webhook dispute names no local record; reconciliation must explain the divergence.', [
                'square_dispute_id' => (string) ($dispute['id'] ?? ''),
                'square_payment_id' => $paymentId,
            ]);
        }

        return $record;
    }
}
