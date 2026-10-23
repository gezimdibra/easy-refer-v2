<?php

namespace App\Services\Twilio;

use App\Contracts\PhoneVerificationGateway;
use Illuminate\Support\Facades\Log;

/**
 * The unconfigured-environment verification gateway. Mirrors the
 * Phase 1 Square pattern: with no provider keys the flow stays
 * closed with the generic unavailable outcome rather than failing
 * open or erroring. Never approves anything.
 */
class NullPhoneVerificationGateway implements PhoneVerificationGateway
{
    public function start(string $phoneE164, string $channel = 'sms'): array
    {
        Log::info('Phone verification deferred: no Twilio Verify configuration.');

        return ['outcome' => 'provider_error', 'provider_status' => null];
    }

    public function check(string $phoneE164, string $code): array
    {
        Log::info('Phone verification check deferred: no Twilio Verify configuration.');

        return ['outcome' => 'denied', 'provider_status' => null];
    }
}
