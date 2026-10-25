<?php

namespace App\Services\Twilio;

use App\Contracts\MarketingSmsGateway;
use App\Support\SmsSegments;
use Illuminate\Support\Facades\Log;

/**
 * The unconfigured-environment marketing gateway: records the
 * intent and returns null. Campaign surfaces check for this
 * binding and render their closed-but-graceful state before a
 * send can ever be attempted, so a send reaching this class is
 * a wiring error, logged as one. Never throws.
 */
class NullMarketingSmsGateway implements MarketingSmsGateway
{
    public function send(string $toPhoneE164, string $body): ?string
    {
        Log::info('Marketing SMS deferred: no Twilio marketing configuration.');

        return null;
    }

    public function segmentCount(string $body): int
    {
        return SmsSegments::count($body);
    }
}
