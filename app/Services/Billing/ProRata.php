<?php

namespace App\Services\Billing;

use InvalidArgumentException;

/**
 * The one rounding rule (spec Section 9.8, normative): when a
 * total is split across units, each unit gets floor(share) cents,
 * then the remaining cents are distributed one at a time to the
 * earliest-created units until the remainder is exhausted. The
 * allocated sum always equals the total exactly; no unit is ever
 * rounded independently. All amounts are integer cents; negative
 * totals and empty unit counts are refused outright (EC-34: no
 * negative amounts anywhere in the engine).
 */
final class ProRata
{
    /**
     * Split a total across equal units, earliest first.
     *
     * @return list<int> per-unit cents, in unit order
     */
    public static function allocate(int $totalCents, int $units): array
    {
        if ($units < 1) {
            throw new InvalidArgumentException('Pro-rata split needs at least one unit.');
        }

        if ($totalCents < 0) {
            throw new InvalidArgumentException('Pro-rata split refuses a negative total.');
        }

        $share = intdiv($totalCents, $units);
        $remainder = $totalCents - ($share * $units);

        $allocation = [];
        for ($i = 0; $i < $units; $i++) {
            $allocation[] = $share + ($i < $remainder ? 1 : 0);
        }

        return $allocation;
    }

    /**
     * Split a total across units of differing size (a refund
     * pro-rated across visits of differing amounts): each unit's
     * raw share is total x unit / sum(units); floors are taken,
     * then the remaining cents go one at a time to the
     * earliest-created units. The sum is exact.
     *
     * @param  list<int>  $unitAmounts
     * @return list<int> per-unit cents, in unit order
     */
    public static function allocateWeighted(int $totalCents, array $unitAmounts): array
    {
        if ($unitAmounts === []) {
            throw new InvalidArgumentException('Pro-rata split needs at least one unit.');
        }

        if ($totalCents < 0) {
            throw new InvalidArgumentException('Pro-rata split refuses a negative total.');
        }

        $sum = array_sum($unitAmounts);

        if ($sum <= 0) {
            throw new InvalidArgumentException('Pro-rata split needs a positive unit total.');
        }

        $allocation = [];
        $allocated = 0;
        foreach ($unitAmounts as $amount) {
            $share = intdiv($totalCents * $amount, $sum);
            $allocation[] = $share;
            $allocated += $share;
        }

        $remainder = $totalCents - $allocated;
        $count = count($allocation);
        for ($i = 0; $remainder > 0; $i = ($i + 1) % $count) {
            $allocation[$i]++;
            $remainder--;
        }

        return $allocation;
    }
}
