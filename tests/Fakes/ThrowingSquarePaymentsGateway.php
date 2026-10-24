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

/**
 * A payments gateway double whose every call ends the way a
 * Square timeout does: no definitive answer, so callers must
 * leave their records pending for the sweeper (EC-33).
 */
class ThrowingSquarePaymentsGateway implements SquarePaymentsGateway
{
    public function chargeCardOnFile(Business $business, PaymentMethod $card, int $amountCents, string $idempotencyKey, array $lineItems): ChargeResult
    {
        throw new SquareOutcomeUnknown('Simulated Square timeout on charge.');
    }

    public function resolveCharge(BillingRecord $record): ?ChargeResult
    {
        throw new SquareOutcomeUnknown('Simulated Square timeout on resolve.');
    }

    public function saveCard(Business $business, string $sourceId, string $idempotencyKey): CardResult
    {
        throw new SquareOutcomeUnknown('Simulated Square timeout on card save.');
    }

    public function refundPayment(string $squarePaymentId, int $amountCents, string $idempotencyKey, ?string $reason = null): RefundResult
    {
        throw new SquareOutcomeUnknown('Simulated Square timeout on refund.');
    }
}
