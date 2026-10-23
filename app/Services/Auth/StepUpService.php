<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Step-up authentication (spec Section 8.7). A logged-in session
 * alone does not authorize the actions that can move money or end
 * the account; each requires a fresh verification, the current
 * password re-entered or a TOTP code where 2FA is enrolled, inside
 * the configured window. The verification is a session marker with a
 * timestamp, scoped to this session and expiring with the window; it
 * never widens into a general trust flag.
 *
 * Later phases declare their enforcement points (the step-up
 * middleware on a route, as on the email change route today). A flow
 * that verifies the current password inline for its own action, the
 * password change, verifies through this service too and marks the
 * window fresh, so the mechanism is the single implementation of the
 * rule.
 */
class StepUpService
{
    public const SESSION_KEY = 'auth.step_up_at';

    public function __construct(private TwoFactorService $twoFactor) {}

    public function markVerified(Session $session): void
    {
        $session->put(self::SESSION_KEY, now()->timestamp);
    }

    public function isFresh(Session $session): bool
    {
        $verifiedAt = $session->get(self::SESSION_KEY);

        if ($verifiedAt === null) {
            return false;
        }

        return now()->timestamp - (int) $verifiedAt <= (int) config('security.step_up.window_seconds');
    }

    /**
     * Verify a current-password re-entry (the step-up challenge for
     * accounts without 2FA, and the in-request proof for flows that
     * collect the password themselves).
     */
    public function verifyPassword(User $user, ?string $password): bool
    {
        return $password !== null && $password !== ''
            && password_verify($password, $user->password);
    }

    /**
     * Verify a step-up challenge submission: password re-entry, or a
     * TOTP code where 2FA is enrolled.
     */
    public function verifyCredential(User $user, ?string $password, ?string $code): bool
    {
        if ($password !== null && $password !== '') {
            return $this->verifyPassword($user, $password);
        }

        if ($code !== null && $code !== '' && $user->hasTwoFactorEnabled()) {
            return $this->twoFactor->verifyCode($user, $code);
        }

        return false;
    }
}
