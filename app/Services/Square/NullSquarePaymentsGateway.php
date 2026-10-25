<?php

namespace App\Services\Square;

use App\Contracts\SquarePaymentsGateway;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Services\Billing\CardResult;
use App\Services\Billing\ChargeResult;
use App\Services\Billing\RefundResult;
use Illuminate\Support\Facades\Log;

/**
 * The payments gateway when no Square access token is
 * configured: money movement is closed, but every caller gets a
 * definitive, truthful failure instead of an exception, so the
 * engine's records land in `failed` with the reason on the row
 * and the events are released for a later, configured run. This
 * is the same pending-state discipline as the null customer
 * gateway (spec Sections 4.1 and 9).
 */
class NullSquarePaymentsGateway implements SquarePaymentsGateway
{
    public function chargeCardOnFile(Business $business, PaymentMethod $card, int $amountCents, string $idempotencyKey, array $lineItems): ChargeResult
    {
        Log::error('Square charge attempted without a configured access token.', [
            'business_id' => $business->id,
        ]);

        return ChargeResult::failed('square_not_configured', 'integration');
    }

    public function resolveCharge(BillingRecord $record): ?ChargeResult
    {
        return null;
    }

    public function saveCard(Business $business, string $sourceId, string $idempotencyKey): CardResult
    {
        Log::error('Square card save attempted without a configured access token.', [
            'business_id' => $business->id,
        ]);

        return CardResult::failed('square_not_configured', 'integration');
    }

    public function resolveCard(PaymentMethod $method): ?CardResult
    {
        return null;
    }

    public function refundPayment(string $squarePaymentId, int $amountCents, string $idempotencyKey, ?string $reason = null): RefundResult
    {
        Log::error('Square refund attempted without a configured access token.');

        return RefundResult::failed('square_not_configured', 'integration');
    }
}
