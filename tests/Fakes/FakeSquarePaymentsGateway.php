<?php

namespace Tests\Fakes;

use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Services\Billing\CardResult;
use App\Services\Billing\ChargeResult;
use App\Services\Billing\RefundResult;
use App\Services\Billing\SquareOutcomeUnknown;
use Throwable;

/**
 * Test double for the payments gateway contract, modeling the
 * Square behaviors the engine depends on: a charge (or card save,
 * or refund) repeated under the same idempotency key returns the
 * original result and is NOT executed twice; resolveCharge
 * answers from what the fake "Square" holds for a key (seeded by
 * a prior charge, or by the test through resolvedCharges). Every
 * executed call is recorded for assertions.
 */
class FakeSquarePaymentsGateway implements SquarePaymentsGateway
{
    /** @var list<array{business_id: int, amount_cents: int, key: string, line_items: array}> */
    public array $chargeCalls = [];

    /** @var list<array{business_id: int, key: string}> */
    public array $saveCardCalls = [];

    /** @var list<array{payment_id: string, amount_cents: int, key: string}> */
    public array $refundCalls = [];

    /** @var array<string, ChargeResult> what Square holds, by idempotency key */
    public array $chargesByKey = [];

    /** @var array<string, ChargeResult> seedable resolutions for the sweeper */
    public array $resolvedCharges = [];

    public ?Throwable $chargeException = null;

    public ?Throwable $saveCardException = null;

    public ?Throwable $refundException = null;

    public ?string $chargeFailureCode = null;

    public ?string $cardFailureCode = null;

    public ?string $refundFailureCode = null;

    private int $paymentSequence = 0;

    private int $refundSequence = 0;

    public function chargeCardOnFile(Business $business, PaymentMethod $card, int $amountCents, string $idempotencyKey, array $lineItems): ChargeResult
    {
        if (isset($this->chargesByKey[$idempotencyKey])) {
            return $this->chargesByKey[$idempotencyKey];
        }

        if ($this->chargeException !== null) {
            $exception = $this->chargeException;
            $this->chargeException = null;
            throw $exception;
        }

        $this->chargeCalls[] = [
            'business_id' => $business->id,
            'amount_cents' => $amountCents,
            'key' => $idempotencyKey,
            'line_items' => $lineItems,
        ];

        if ($this->chargeFailureCode !== null) {
            return $this->chargesByKey[$idempotencyKey] = ChargeResult::failed($this->chargeFailureCode, 'api');
        }

        $this->paymentSequence++;

        return $this->chargesByKey[$idempotencyKey] = ChargeResult::succeeded(
            'sq_payment_fake_'.$this->paymentSequence,
            'sq_order_fake_'.$this->paymentSequence,
        );
    }

    public function resolveCharge(BillingRecord $record): ?ChargeResult
    {
        return $this->resolvedCharges[$record->idempotency_key]
            ?? $this->chargesByKey[$record->idempotency_key]
            ?? null;
    }

    public function saveCard(Business $business, string $sourceId, string $idempotencyKey): CardResult
    {
        if ($this->saveCardException !== null) {
            $exception = $this->saveCardException;
            $this->saveCardException = null;
            throw $exception;
        }

        $this->saveCardCalls[] = ['business_id' => $business->id, 'key' => $idempotencyKey];

        if ($this->cardFailureCode !== null) {
            return CardResult::failed($this->cardFailureCode, 'api');
        }

        return CardResult::succeeded('sq_card_fake_'.$business->id.'_'.count($this->saveCardCalls), 'VISA', '4242', 12, 2030);
    }

    public function refundPayment(string $squarePaymentId, int $amountCents, string $idempotencyKey, ?string $reason = null): RefundResult
    {
        if ($this->refundException !== null) {
            $exception = $this->refundException;
            $this->refundException = null;
            throw $exception;
        }

        $this->refundCalls[] = [
            'payment_id' => $squarePaymentId,
            'amount_cents' => $amountCents,
            'key' => $idempotencyKey,
        ];

        if ($this->refundFailureCode !== null) {
            return RefundResult::failed($this->refundFailureCode, 'api');
        }

        $this->refundSequence++;

        return RefundResult::succeeded('sq_refund_fake_'.$this->refundSequence);
    }
}
