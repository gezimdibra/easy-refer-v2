<?php

namespace Tests\Unit;

use App\Services\Billing\ProRata;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The Section 9.8 rounding rule, pinned at half-cent boundaries
 * (BILL-19): floor(share) to each unit, remainder cents one at a
 * time to the earliest units, sum always exact. No unit is ever
 * rounded independently, and negative totals are refused.
 */
class ProRataTest extends TestCase
{
    public function test_even_split_has_no_remainder(): void
    {
        $this->assertSame([200, 200, 200], ProRata::allocate(600, 3));
    }

    public function test_remainder_goes_to_the_earliest_units_one_cent_at_a_time(): void
    {
        $this->assertSame([34, 33, 33], ProRata::allocate(100, 3));
        $this->assertSame([34, 34, 33], ProRata::allocate(101, 3));
        $this->assertSame([34, 34, 34], ProRata::allocate(102, 3));
    }

    public function test_half_cent_boundaries(): void
    {
        // The .005 cases: totals that sit exactly between two
        // whole-cent shares resolve by the rule, never by float.
        $this->assertSame([3, 2], ProRata::allocate(5, 2));
        $this->assertSame([1, 0], ProRata::allocate(1, 2));
        $this->assertSame([1, 1, 0], ProRata::allocate(2, 3));
        $this->assertSame([0, 0, 0], ProRata::allocate(0, 3));
    }

    public function test_sum_is_always_exact(): void
    {
        foreach ([1, 7, 99, 100, 101, 9999, 9900] as $total) {
            foreach ([1, 2, 3, 7, 49] as $units) {
                $this->assertSame($total, array_sum(ProRata::allocate($total, $units)), "total {$total} over {$units} units");
            }
        }
    }

    public function test_negative_total_and_empty_units_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProRata::allocate(-100, 3);
    }

    public function test_zero_units_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProRata::allocate(100, 0);
    }

    public function test_weighted_split_pro_rates_by_unit_size(): void
    {
        // Refund of 250 across three 200-cent visits: raw shares
        // are 83.33 each; floors give 249 and the remainder cent
        // goes to the earliest visit.
        $this->assertSame([84, 83, 83], ProRata::allocateWeighted(250, [200, 200, 200]));
        $this->assertSame([100, 300], ProRata::allocateWeighted(400, [100, 300]));
        $this->assertSame([1, 0], ProRata::allocateWeighted(1, [200, 200]));
    }

    public function test_weighted_split_sum_is_exact_and_refuses_bad_input(): void
    {
        $this->assertSame(333, array_sum(ProRata::allocateWeighted(333, [200, 100, 50, 25])));

        $this->expectException(InvalidArgumentException::class);
        ProRata::allocateWeighted(100, []);
    }
}
