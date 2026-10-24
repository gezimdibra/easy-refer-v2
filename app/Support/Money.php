<?php

namespace App\Support;

/**
 * Money rendering for copy slots: marketing surfaces show whole
 * dollars without decimals ("$2", "$99", per the approved inventory
 * lines) and cents-exact surfaces use number formatting at the call
 * site. Amounts always derive from integer cents in the plan table,
 * never from literals in templates.
 */
final class Money
{
    public static function dollars(int $cents): string
    {
        return $cents % 100 === 0
            ? number_format(intdiv($cents, 100))
            : number_format($cents / 100, 2);
    }

    public static function usd(int $cents): string
    {
        return '$'.self::dollars($cents);
    }
}
