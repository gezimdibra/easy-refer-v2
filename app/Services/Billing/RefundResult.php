<?php

namespace App\Services\Billing;

/**
 * The outcome of a refund attempt at the Square boundary, with
 * the same ambiguity discipline as ChargeResult: definitive
 * answers are results, unknown outcomes throw
 * SquareOutcomeUnknown.
 */
final class RefundResult
{
    private function __construct(
        public readonly bool $succeeded,
        public readonly ?string $refundId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorCategory = null,
    ) {}

    public static function succeeded(string $refundId): self
    {
        return new self(true, $refundId);
    }

    public static function failed(string $errorCode, ?string $errorCategory = null): self
    {
        return new self(false, null, $errorCode, $errorCategory);
    }
}
