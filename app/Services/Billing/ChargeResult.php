<?php

namespace App\Services\Billing;

/**
 * The outcome of a charge attempt at the Square boundary. A
 * result exists only when Square gave a definitive answer; an
 * ambiguous outcome (timeout, transport failure) is signaled by
 * throwing SquareOutcomeUnknown instead, because the charge may
 * or may not exist and only the persisted idempotency key can
 * resolve it (spec Section 9.3, EC-33).
 */
final class ChargeResult
{
    private function __construct(
        public readonly bool $succeeded,
        public readonly ?string $paymentId = null,
        public readonly ?string $orderId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorCategory = null,
    ) {}

    public static function succeeded(string $paymentId, ?string $orderId = null): self
    {
        return new self(true, $paymentId, $orderId);
    }

    public static function failed(string $errorCode, ?string $errorCategory = null): self
    {
        return new self(false, null, null, $errorCode, $errorCategory);
    }
}
