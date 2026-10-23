<?php

namespace App\Services\Twilio;

use App\Contracts\SmsGateway;
use App\Support\SmsSegments;
use Illuminate\Support\Facades\Log;

/**
 * The unconfigured-environment SMS gateway: records the intent and
 * returns null, so callers exercise their failure paths (the reward
 * notification stays queued and retries, never silently dropped;
 * EC-23). Never throws.
 */
class NullSmsGateway implements SmsGateway
{
    public function send(string $toPhoneE164, string $body): ?string
    {
        Log::info('Transactional SMS deferred: no Twilio messaging configuration.');

        return null;
    }

    public function segmentCount(string $body): int
    {
        return SmsSegments::count($body);
    }
}
