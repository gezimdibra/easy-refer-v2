<?php

namespace App\Services\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\BillingRefund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Refunds against a funded batch (spec Section 9.8, blueprint
 * Section 1.7, EC-31 and EC-32). The refund row exists in
 * `pending` with its key before the Refunds API call; the key is
 * refund_{record_id}_{refund_id}, both local IDs, so it is
 * deterministic for the row and unique by construction (the
 * blueprint's literal refund_{record}_{cents} shape collides
 * when two equal partial refunds hit one record).
 *
 * Visit selection: the refund is absorbed by the batch's visits
 * in creation order, earliest first; a visit absorbed in full is
 * marked refunded and its reward follows the OD-3 clawback path,
 * a visit absorbed only in part keeps its coverage (the partial
 * remainder lives on the refund row, exact to the cent). This is
 * the reading the sandbox matrix pins (SQ-15: refunding one
 * visit's worth refunds exactly one visit; the others are
 * untouched). Per-visit accounting splits of a refund total use
 * the Section 9.8 rounding rule in ProRata.
 */
class RefundService
{
    public function __construct(
        private SquarePaymentsGateway $gateway,
        private RewardClawback $clawback,
    ) {}

    /**
     * @throws InvalidArgumentException on an unrefundable record or an out-of-range amount
     * @throws SquareOutcomeUnknown when Square's answer is unknown (the refund row stays pending)
     */
    public function refund(BillingRecord $record, ?int $amountCents = null, ?string $reason = null, string $actor = 'owner'): BillingRefund
    {
        if (! in_array($record->state, [BillingRecord::STATE_SUCCEEDED, BillingRecord::STATE_PARTIALLY_REFUNDED], true)) {
            throw new InvalidArgumentException('Billing record '.$record->id.' is not in a refundable state.');
        }

        if ($record->square_payment_id === null) {
            throw new InvalidArgumentException('Billing record '.$record->id.' has no Square payment to refund.');
        }

        $alreadyRefunded = (int) $record->refunds()
            ->where('state', BillingRefund::STATE_SUCCEEDED)
            ->sum('amount_cents');
        $refundable = (int) $record->amount_cents - $alreadyRefunded;
        $amount = $amountCents ?? $refundable;

        if ($amount < 1 || $amount > $refundable) {
            throw new InvalidArgumentException('Refund amount '.$amount.' is outside the refundable remainder '.$refundable.'.');
        }

        $refund = BillingRefund::create([
            'billing_record_id' => $record->id,
            'amount_cents' => $amount,
            'reason' => $reason,
            'state' => BillingRefund::STATE_PENDING,
        ]);

        $refund->forceFill([
            'idempotency_key' => 'refund_'.$record->id.'_'.$refund->id,
        ])->save();

        $result = $this->gateway->refundPayment($record->square_payment_id, $amount, $refund->idempotency_key, $reason);

        if (! $result->succeeded) {
            $refund->forceFill(['state' => BillingRefund::STATE_FAILED])->save();

            return $refund;
        }

        DB::transaction(function () use ($record, $refund, $result, $amount, $reason, $actor) {
            $refund->forceFill([
                'state' => BillingRefund::STATE_SUCCEEDED,
                'square_refund_id' => $result->refundId,
            ])->save();

            $this->applyRefundEffects($record, $amount, $actor, $reason);
        });

        return $refund;
    }

    /**
     * Apply a refund Square reports by webhook (spec Sections
     * 9.4 and 9.8; billing UI spec Section 4 flag 2): a refund
     * created outside the app (the Square dashboard) or a status
     * change on one of ours. The row is matched by its Square
     * refund ID; an event for a refund our own flow left
     * unresolved (pending, no Square ID yet) adopts that single
     * pending row when the amount agrees, which is the
     * status-refresh path the Phase 4 report owed; anything else
     * is an external refund and is created, with the shipped
     * deterministic key assigned on creation exactly as the
     * local flow assigns it.
     *
     * States are monotonic: pending may resolve either way;
     * succeeded and failed are absorbing. An event that would
     * reverse a settled row is a divergence, logged Critical
     * for the Section 9.7 reconciliation, never applied.
     *
     * @param  array<string, mixed>  $refund  the event's refund object
     */
    public function recordRefundFromWebhook(array $refund): void
    {
        $squareRefundId = (string) ($refund['id'] ?? '');
        $paymentId = (string) ($refund['payment_id'] ?? '');
        $amount = (int) ($refund['amount_money']['amount'] ?? 0);

        if ($squareRefundId === '' || $paymentId === '' || $amount < 1) {
            return;
        }

        $record = BillingRecord::query()->where('square_payment_id', $paymentId)->first();

        if ($record === null) {
            Log::critical('Square webhook refund names no local record; reconciliation must explain the divergence.', [
                'square_refund_id' => $squareRefundId,
                'square_payment_id' => $paymentId,
            ]);

            return;
        }

        $targetState = match (strtoupper((string) ($refund['status'] ?? ''))) {
            'COMPLETED' => BillingRefund::STATE_SUCCEEDED,
            'REJECTED', 'FAILED' => BillingRefund::STATE_FAILED,
            default => BillingRefund::STATE_PENDING,
        };

        DB::transaction(function () use ($record, $refund, $squareRefundId, $amount, $targetState) {
            $row = BillingRefund::query()->where('square_refund_id', $squareRefundId)->first();

            if ($row === null) {
                $candidates = BillingRefund::query()
                    ->where('billing_record_id', $record->id)
                    ->where('state', BillingRefund::STATE_PENDING)
                    ->whereNull('square_refund_id')
                    ->where('amount_cents', $amount)
                    ->get();

                if ($candidates->count() === 1) {
                    $row = $candidates->first();
                    $row->forceFill(['square_refund_id' => $squareRefundId])->save();
                }
            }

            if ($row === null) {
                $row = BillingRefund::create([
                    'billing_record_id' => $record->id,
                    'square_refund_id' => $squareRefundId,
                    'amount_cents' => $amount,
                    'reason' => isset($refund['reason']) ? (string) $refund['reason'] : null,
                    'state' => BillingRefund::STATE_PENDING,
                ]);
                $row->forceFill([
                    'idempotency_key' => 'refund_'.$record->id.'_'.$row->id,
                ])->save();
            }

            if ($row->billing_record_id !== $record->id) {
                Log::critical('Square webhook refund is recorded against a different local record; reconciliation must explain the divergence.', [
                    'square_refund_id' => $squareRefundId,
                    'billing_record_id' => $row->billing_record_id,
                    'event_record_id' => $record->id,
                ]);

                return;
            }

            if (in_array($row->state, [BillingRefund::STATE_SUCCEEDED, BillingRefund::STATE_FAILED], true)) {
                if ($row->state !== $targetState && $targetState !== BillingRefund::STATE_PENDING) {
                    Log::critical('Square webhook refund would reverse a settled local refund; reconciliation must explain the divergence.', [
                        'square_refund_id' => $squareRefundId,
                        'local_state' => $row->state,
                        'reported_state' => $targetState,
                    ]);
                }

                return;
            }

            if ($targetState === $row->state) {
                return;
            }

            $row->forceFill(['state' => $targetState])->save();

            if ($targetState === BillingRefund::STATE_SUCCEEDED) {
                $this->applyRefundEffects($record, $amount, 'webhook', $row->reason);
            }
        });
    }

    /**
     * Run the clawback walk over every visit a record funded
     * (spec Section 9.8, dispute loss): the same path a full
     * refund takes, without a billing_refunds row, because the
     * debit is Square's dispute decision, not a refund.
     */
    public function clawbackAllVisits(BillingRecord $record, string $actor = 'webhook', ?string $reason = null): void
    {
        $this->markRefundedVisits($record, (int) $record->amount_cents, $actor, $reason);
    }

    /**
     * The effects of a refund reaching `succeeded`, shared by
     * the local flow and the webhook path: the visits absorb the
     * amount in creation order (and their rewards follow the
     * OD-3 clawback), then the record's state is recomputed from
     * its succeeded refund rows. The caller marks the refund row
     * succeeded first, inside the same transaction. A disputed
     * record keeps its state: the dispute resolution owns it.
     */
    private function applyRefundEffects(BillingRecord $record, int $amountCents, string $actor, ?string $reason): void
    {
        $this->markRefundedVisits($record, $amountCents, $actor, $reason);

        if (! in_array($record->state, [
            BillingRecord::STATE_SUCCEEDED,
            BillingRecord::STATE_PARTIALLY_REFUNDED,
            BillingRecord::STATE_REFUNDED,
        ], true)) {
            return;
        }

        $totalRefunded = (int) $record->refunds()
            ->where('state', BillingRefund::STATE_SUCCEEDED)
            ->sum('amount_cents');

        $record->forceFill([
            'state' => $totalRefunded >= (int) $record->amount_cents
                ? BillingRecord::STATE_REFUNDED
                : BillingRecord::STATE_PARTIALLY_REFUNDED,
        ])->save();
    }

    /**
     * Absorb the refund across the record's billed visits in
     * creation order: fully absorbed visits are marked refunded
     * and clawed back; the first partially absorbed visit ends
     * the walk (its coverage is unchanged; the partial cents are
     * proven by the refund row itself).
     */
    private function markRefundedVisits(BillingRecord $record, int $amountCents, string $actor, ?string $reason): void
    {
        $remaining = $amountCents;

        $events = BillingEvent::query()
            ->where('billing_record_id', $record->id)
            ->where('coverage', '!=', 'refunded')
            ->orderBy('id')
            ->get();

        foreach ($events as $event) {
            if ($remaining < (int) $event->amount_cents) {
                break;
            }

            $remaining -= (int) $event->amount_cents;
            $event->forceFill(['coverage' => 'refunded'])->save();
            $this->clawback->clawbackEvent($event, $actor, $reason);
        }
    }
}
