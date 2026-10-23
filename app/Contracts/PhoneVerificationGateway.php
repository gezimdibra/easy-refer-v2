<?php

namespace App\Contracts;

/**
 * Phone verification (spec Section 10, twilio-blueprint Section 1).
 * The provider owns the code lifecycle end to end: v2 never
 * generates, stores, or compares a code. Implementations return the
 * provider's outcome vocabulary; callers map it to product behavior.
 */
interface PhoneVerificationGateway
{
    /**
     * Start a verification for a canonical E.164 number. $channel is
     * 'sms' or 'call' (EC-20: landline and no-text fallbacks ride the
     * call channel on user request).
     *
     * Result keys: outcome ('started', 'landline_blocked', 'rate_limited',
     * 'provider_error'), provider_status (nullable string).
     *
     * @return array{outcome: string, provider_status: ?string}
     */
    public function start(string $phoneE164, string $channel = 'sms'): array;

    /**
     * Check a submitted code against the pending verification.
     *
     * Result keys: outcome ('approved', 'pending', 'denied',
     * 'provider_error'), provider_status (nullable string).
     *
     * @return array{outcome: string, provider_status: ?string}
     */
    public function check(string $phoneE164, string $code): array;
}
