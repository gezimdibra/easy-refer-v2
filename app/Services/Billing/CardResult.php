<?php

namespace App\Services\Billing;

/**
 * The outcome of a card-on-file save at the Square boundary.
 * Brand and last4 come from the CreateCard response only, never
 * from client data (blueprint Section 1.1).
 */
final class CardResult
{
    private function __construct(
        public readonly bool $succeeded,
        public readonly ?string $cardId = null,
        public readonly ?string $brand = null,
        public readonly ?string $last4 = null,
        public readonly ?int $expMonth = null,
        public readonly ?int $expYear = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorCategory = null,
    ) {}

    public static function succeeded(string $cardId, ?string $brand, ?string $last4, ?int $expMonth, ?int $expYear): self
    {
        return new self(true, $cardId, $brand, $last4, $expMonth, $expYear);
    }

    public static function failed(string $errorCode, ?string $errorCategory = null): self
    {
        return new self(false, null, null, null, null, null, $errorCode, $errorCategory);
    }
}
