<?php

namespace App\Support;

/**
 * Phone normalization to canonical E.164 (spec Section 3.1: one customer
 * identity is one canonical E.164 number; every format variant collapses
 * before any lookup, limit, or constraint).
 *
 * The platform's launch scope is +1 (spec Section 10.1 geo lock). Numbers
 * entered with an explicit country code are kept when they are valid
 * E.164; bare national numbers are read as +1 numbers.
 */
final class PhoneNumber
{
    public static function normalizeToE164(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $input = trim($input);

        if ($input === '') {
            return null;
        }

        if (str_starts_with($input, '+')) {
            $digits = preg_replace('/\D+/', '', $input);

            return preg_match('/^[1-9]\d{6,14}$/', (string) $digits) === 1
                ? '+'.$digits
                : null;
        }

        $digits = preg_replace('/\D+/', '', $input);

        // The 00 international prefix is the plus sign written out
        // (an EC-15 battery variant): collapse it the same way.
        if (str_starts_with((string) $digits, '00')) {
            $international = substr((string) $digits, 2);

            return preg_match('/^[1-9]\d{6,14}$/', $international) === 1
                ? '+'.$international
                : null;
        }

        if (strlen((string) $digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen((string) $digits) === 11 && str_starts_with((string) $digits, '1')) {
            return '+'.$digits;
        }

        return null;
    }
}
