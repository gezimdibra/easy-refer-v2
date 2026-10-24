<?php

namespace App\Services\Billing;

use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\BillingRefund;
use Illuminate\Support\Facades\DB;
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

        DB::transaction(function () use ($record, $refund, $result, $amount, $reason, $actor, $alreadyRefunded) {
            $refund->forceFill([
                'state' => BillingRefund::STATE_SUCCEEDED,
                'square_refund_id' => $result->refundId,
            ])->save();

            $this->markRefundedVisits($record, $amount, $actor, $reason);

            $record->forceFill([
                'state' => ($alreadyRefunded + $amount) >= (int) $record->amount_cents
                    ? BillingRecord::STATE_REFUNDED
                    : BillingRecord::STATE_PARTIALLY_REFUNDED,
            ])->save();
        });

        return $refund;
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
