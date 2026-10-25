<?php

namespace App\Support;

/**
 * The one source for the failed-payment words (SV-03, Train 2;
 * copy Amendment 22 BILL-PD-01). The billing banner, the
 * dashboard gate, and the Action Center row all state a failed
 * payment in exactly these words, so the sentence lives here
 * once and every surface reads it from here.
 */
final class BillingCopy
{
    public static function pastDueLine(): string
    {
        return 'A payment failed. Please update your card to keep billing current.';
    }

    public static function graceLine(): string
    {
        return 'Your QR code and customer links keep working during the grace period.';
    }
}
