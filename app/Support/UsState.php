<?php

namespace App\Support;

/**
 * US state normalization for business addresses. The stored value
 * is always the 2-letter code (config/countries.php us_states keys):
 * the Square customer payload sends it as the administrative
 * district, and the signup ZIP-region check compares against it.
 * Owners, though, type the name as often as the code, so every
 * owner-facing address form accepts either form and stores the
 * code: "NY", "ny", "New York" and "new york" all normalize to
 * "NY". Anything else returns null, so the caller can refuse it
 * against the real list instead of storing free text.
 */
final class UsState
{
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $given = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        if ($given === '') {
            return null;
        }

        $states = (array) config('countries.us_states');

        $code = strtoupper($given);

        if (array_key_exists($code, $states)) {
            return $code;
        }

        foreach ($states as $stateCode => $stateName) {
            if (strcasecmp($given, (string) $stateName) === 0) {
                return (string) $stateCode;
            }
        }

        return null;
    }
}
