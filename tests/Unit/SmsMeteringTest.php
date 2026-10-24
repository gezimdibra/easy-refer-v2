<?php

namespace Tests\Unit;

use App\Services\Billing\SmsMetering;
use App\Services\Referral\MessageCopy;
use App\Models\Business;
use Tests\TestCase;

/**
 * SMS metering price pins (Phase 4 coverage map row 8, OD-1):
 * price is segments times the configured 2 cents, per recipient,
 * and a bulk total is the sum over recipients, never an average.
 * Bulk SMS is never free.
 */
class SmsMeteringTest extends TestCase
{
    public function test_price_is_segments_times_the_configured_price(): void
    {
        $this->assertSame(2, (int) config('billing.sms_segment_price_cents'));
        $this->assertSame(2, SmsMetering::priceCentsFor('hello'));
        $this->assertSame(4, SmsMetering::priceCentsFor(str_repeat('a', 161)));
        $this->assertSame(0, SmsMetering::priceCentsFor(''));
        $this->assertSame(6, SmsMetering::priceForSegments(3));
    }

    public function test_price_follows_configuration_not_a_literal(): void
    {
        config(['billing.sms_segment_price_cents' => 5]);

        $this->assertSame(5, SmsMetering::priceCentsFor('hello'));
        $this->assertSame(10, SmsMetering::priceCentsFor(str_repeat('a', 161)));
    }

    public function test_bulk_total_is_the_sum_over_recipients(): void
    {
        $oneSegment = 'Your reward is ready';
        $twoSegments = str_repeat('a', 161);
        $unicodeTwo = str_repeat('a', 69).'😀';

        $this->assertSame(
            2 + 4 + 4,
            SmsMetering::totalCentsFor([$oneSegment, $twoSegments, $unicodeTwo]),
        );
        $this->assertSame(0, SmsMetering::totalCentsFor([]));
    }

    public function test_the_reward_ready_template_meters_at_two_segments(): void
    {
        $business = new Business(['name' => "Luigi's Pizza"]);
        $body = MessageCopy::rewardReady($business, '$10 off', 'https://easy-refer.com/new/refer/c/AbCdEfGhIjKlMnOpQrStUv');

        $this->assertSame(2, SmsMetering::segmentsFor($body));
        $this->assertSame(4, SmsMetering::priceCentsFor($body));
    }
}
