<?php

namespace Tests\Unit;

use App\Support\Money;
use Tests\TestCase;

/**
 * Money rendering pins (Phase 4 coverage map row 9). This class
 * renders the price on every marketing surface, so the whole-dollar
 * and cents-exact shapes are pinned exactly.
 */
class MoneyTest extends TestCase
{
    public function test_whole_dollars_render_without_decimals(): void
    {
        $this->assertSame('99', Money::dollars(9900));
        $this->assertSame('$99', Money::usd(9900));
        $this->assertSame('2', Money::dollars(200));
        $this->assertSame('$2', Money::usd(200));
        $this->assertSame('1', Money::dollars(100));
        $this->assertSame('$1', Money::usd(100));
    }

    public function test_zero_renders_as_zero(): void
    {
        $this->assertSame('0', Money::dollars(0));
        $this->assertSame('$0', Money::usd(0));
    }

    public function test_cents_render_with_two_decimals(): void
    {
        $this->assertSame('98.50', Money::dollars(9850));
        $this->assertSame('$98.50', Money::usd(9850));
        $this->assertSame('0.50', Money::dollars(50));
        $this->assertSame('1.05', Money::dollars(105));
        $this->assertSame('0.02', Money::dollars(2));
    }

    public function test_large_amounts_with_cents_are_number_formatted(): void
    {
        $this->assertSame('1,234.56', Money::dollars(123456));
        $this->assertSame('$1,234.56', Money::usd(123456));
    }

    public function test_boundary_amounts_across_the_grouping_thresholds(): void
    {
        // Cents-side boundary set: each input is integer cents.
        $this->assertSame('9.99', Money::dollars(999));
        $this->assertSame('10', Money::dollars(1000));
        $this->assertSame('99.99', Money::dollars(9999));
        $this->assertSame('100', Money::dollars(10000));
        $this->assertSame('1,000', Money::dollars(100000));
        $this->assertSame('10,000', Money::dollars(1000000));

        // Dollar-side equivalents: the dollar value at each
        // grouping threshold, expressed in cents.
        $this->assertSame('999', Money::dollars(99900));
        $this->assertSame('1,000', Money::dollars(100000));
        $this->assertSame('9,999', Money::dollars(999900));
        $this->assertSame('10,000', Money::dollars(1000000));
        $this->assertSame('100,000', Money::dollars(10000000));
        $this->assertSame('1,000,000', Money::dollars(100000000));

        // The fractional branch at the same magnitudes (already
        // grouped before the fix; pinned so the fix cannot
        // regress it).
        $this->assertSame('10,000.50', Money::dollars(1000050));
        $this->assertSame('999,999.99', Money::dollars(99999999));
    }

    public function test_whole_dollar_amounts_at_and_above_one_thousand_are_grouped(): void
    {
        // F-B regression pin: the whole-dollar branch used to skip
        // number_format, so $1,000 and above lost the separator
        // (dollars(1000000) rendered "10000").
        $this->assertSame('1,000', Money::dollars(100000));
        $this->assertSame('$1,000', Money::usd(100000));
        $this->assertSame('10,000', Money::dollars(1000000));
        $this->assertSame('$10,000', Money::usd(1000000));
        $this->assertSame('1,000,000', Money::dollars(100000000));
        $this->assertSame('$1,000,000', Money::usd(100000000));
    }

    public function test_negative_amounts_render_with_sign_and_grouping(): void
    {
        $this->assertSame('-1', Money::dollars(-100));
        $this->assertSame('-1.50', Money::dollars(-150));
        $this->assertSame('-9.99', Money::dollars(-999));
        $this->assertSame('-1,000', Money::dollars(-100000));
        $this->assertSame('$-1,000', Money::usd(-100000));
        $this->assertSame('-10,000', Money::dollars(-1000000));
    }
}
