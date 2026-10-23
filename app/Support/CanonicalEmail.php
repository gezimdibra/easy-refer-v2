<?php

namespace App\Support;

/**
 * Email canonicalization for identity matching (spec Section 4.1 and
 * EC-18): comparison is case-insensitive and ignores plus-addressing
 * tags, so the same mailbox cannot register twice under cosmetic
 * variants. The address as given is stored separately for display and
 * delivery; this canonical form is what uniqueness checks compare.
 */
final class CanonicalEmail
{
    public static function of(string $email): string
    {
        $email = mb_strtolower(trim($email));

        $at = strrpos($email, '@');

        if ($at === false) {
            return $email;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        $plus = strpos($local, '+');

        if ($plus !== false) {
            $local = substr($local, 0, $plus);
        }

        return $local.'@'.$domain;
    }
}
