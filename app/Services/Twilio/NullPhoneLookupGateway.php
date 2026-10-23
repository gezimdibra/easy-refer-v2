<?php

namespace App\Services\Twilio;

use App\Contracts\PhoneLookupGateway;
use Illuminate\Support\Facades\Log;

/**
 * The unconfigured-environment lookup gateway: neutral signals, so
 * the cooling-window step-up simply does not fire.
 */
class NullPhoneLookupGateway implements PhoneLookupGateway
{
    public function lookup(string $phoneE164): array
    {
        Log::info('Phone lookup deferred: no Twilio Lookup configuration.');

        return ['sim_changed_recently' => false, 'line_type' => null, 'carrier' => null];
    }
}
