<?php

namespace App\Contracts;

use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Services\Billing\CardResult;
use App\Services\Billing\ChargeResult;
use App\Services\Billing\RefundResult;
use App\Services\Billing\SquareOutcomeUnknown;

/**
 * The money-movement boundary (spec Section 9, Square blueprint
 * Section 1). Every method takes the deterministic idempotency
 * key the caller persisted on its local record BEFORE the call,
 * and every method either returns a definitive result or throws
 * SquareOutcomeUnknown when no definitive answer exists. No
 * implementation may generate a key, retry under a new key, or
 * accept an amount from anywhere but its arguments, which the
 * caller computed server-side from the plan row and the billing
 * events.
 *
 * Bindings follow the customer gateway's discipline: with a
 * configured access token the SDK gateway runs; without one, the
 * null gateway answers with clean failures, so no environment
 * can half-configure money movement.
 */
interface SquarePaymentsGateway
{
    /**
     * Charge a business's card on file for an assembled batch:
     * one Square Order with one line item per billing event,
     * then one payment against that order (blueprint Section
     * 1.5).
     *
     * @param  list<array{name: string, note: ?string, amount_cents: int}>  $lineItems
     *
     * @throws SquareOutcomeUnknown when Square's answer is unknown
     */
    public function chargeCardOnFile(Business $business, PaymentMethod $card, int $amountCents, string $idempotencyKey, array $lineItems): ChargeResult;

    /**
     * Resolve what Square holds for a pending record's key, for
     * the sweeper. Returns the definitive charge result, or null
     * when Square has nothing for the key (the record is then
     * safe to supersede).
     */
    public function resolveCharge(BillingRecord $record): ?ChargeResult;

    /**
     * Exchange a single-use Web Payments SDK token for a card on
     * file on the business's Square Customer (blueprint Section
     * 1.1). The token is never stored or logged.
     *
     * @throws SquareOutcomeUnknown when Square's answer is unknown
     */
    public function saveCard(Business $business, string $sourceId, string $idempotencyKey): CardResult;

    /**
     * Resolve what Square holds for a card save whose outcome was
     * unknown, for the pending-card resolver (billing UI spec
     * Section 3). The single-use token is gone and was never
     * stored, so the save cannot be replayed; the answer comes
     * from what Square holds for the business's customer. Returns
     * the definitive card result, or null when Square holds no
     * card for the save (the save never completed).
     *
     * @throws SquareOutcomeUnknown when no definitive answer exists
     */
    public function resolveCard(PaymentMethod $method): ?CardResult;

    /**
     * Refund (part of) a payment (blueprint Section 1.7).
     *
     * @throws SquareOutcomeUnknown when Square's answer is unknown
     */
    public function refundPayment(string $squarePaymentId, int $amountCents, string $idempotencyKey, ?string $reason = null): RefundResult;
}
