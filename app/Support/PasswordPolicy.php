<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * The one password rule chain, applied identically at signup, change,
 * and reset (spec Section 8.2). Minimum length and breached-password
 * screening come from config/security.php so the policy is data, not
 * scattered literals.
 */
final class PasswordPolicy
{
    public static function rule(): Password
    {
        $rule = Password::min((int) config('security.password.min_length'))
            ->max((int) config('security.password.max_length'));

        if (config('security.password.breach_screening')) {
            $rule->uncompromised();
        }

        return $rule;
    }
}
