<?php

namespace App\Services\Billing;

use App\Support\SmsSegments;

/**
 * Bulk SMS metering (OD-1, spec Section 10.3): campaign sends are
 * metered per segment per recipient at the configured price and
 * join the monthly charge as their own line; they are never free
 * and never consume the free-visit credit. Transactional sends
 * are platform cost, not metered to the business; this class
 * prices only what the owner sends.
 */
final class SmsMetering
{
    public static function segmentsFor(string $body): int
    {
        return SmsSegments::count($body);
    }

    public static function priceForSegments(int $segments): int
    {
        return $segments * (int) config('billing.sms_segment_price_cents');
    }

    public static function priceCentsFor(string $body): int
    {
        return self::priceForSegments(self::segmentsFor($body));
    }

    /**
     * The bulk total is the sum over recipients: each recipient's
     * rendered body is priced on its own segments, never averaged.
     *
     * @param  iterable<string>  $bodies  one rendered body per recipient
     */
    public static function totalCentsFor(iterable $bodies): int
    {
        $total = 0;
        foreach ($bodies as $body) {
            $total += self::priceCentsFor($body);
        }

        return $total;
    }
}
