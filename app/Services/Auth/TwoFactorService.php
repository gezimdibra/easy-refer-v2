<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Two-factor authentication mechanics (spec Section 8.6): TOTP
 * enrollment, confirmation, disable, and the sign-in challenge.
 *
 * Enrollment, confirmation, and disable all sit behind a fresh
 * password check, per the spec, and a pending enrollment expires
 * after the configured window (finding N-2). The secret is stored through the model's encrypted cast;
 * recovery codes are generated at confirmation, shown exactly once
 * (the caller flashes them to the security page), and stored only as
 * SHA-256 hashes inside the encrypted recovery-codes column, each
 * single-use. Policy (OD-9) lives in config/security.php; this
 * service is the mechanism the policy configures.
 */
class TwoFactorService
{
    /**
     * Begin (or restart) enrollment. Returns the new secret so the
     * caller can present the provisioning material. An unconfirmed
     * secret activates nothing.
     */
    public function startEnrollment(User $user, string $currentPassword): string
    {
        $this->assertCurrentPassword($user, $currentPassword);

        $secret = Totp::generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_pending_at' => now(),
            // A fresh secret starts a fresh consumption ledger; the
            // recorded timestep belongs to the previous secret.
            'two_factor_last_timestep' => null,
        ])->save();

        return $secret;
    }

    /**
     * Confirm a pending enrollment with the account password and a
     * code from the authenticator app (finding N-2: confirmation
     * carries the same fresh password check as enrollment and
     * disable, and a pending enrollment older than the configured
     * window is refused and cleared rather than completed). On
     * success the enrollment activates and the plaintext recovery
     * codes are returned, once; recovery codes are generated only
     * here, so a failed confirmation never issues any.
     *
     * @return list<string>
     */
    public function confirmEnrollment(User $user, string $code, string $currentPassword): array
    {
        $this->assertCurrentPassword($user, $currentPassword);

        $secret = $user->two_factor_secret;

        if (! is_string($secret) || $secret === '') {
            throw ValidationException::withMessages([
                'code' => 'That code did not match. Check the current code in your authenticator app and try again.',
            ]);
        }

        if ($this->pendingEnrollmentExpired($user)) {
            $this->clearPendingEnrollment($user);

            throw ValidationException::withMessages([
                'code' => 'That setup expired before it was finished. Start the setup again.',
            ]);
        }

        if (! $this->verifyCode($user, $code)) {
            throw ValidationException::withMessages([
                'code' => 'That code did not match. Check the current code in your authenticator app and try again.',
            ]);
        }

        $codes = [];
        $count = (int) config('security.two_factor.recovery_code_count');

        for ($i = 0; $i < $count; $i++) {
            $codes[] = $this->formatRecoveryCode(Str::random(10));
        }

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_pending_at' => null,
            'two_factor_recovery_codes' => array_map(
                fn (string $plain) => $this->recoveryCodeHash($plain),
                $codes
            ),
        ])->save();

        return $codes;
    }

    /**
     * A pending enrollment is expired when it has no recorded start
     * (it did not come through startEnrollment) or when its start is
     * older than the configured window.
     */
    private function pendingEnrollmentExpired(User $user): bool
    {
        $pendingAt = $user->two_factor_pending_at;

        if ($pendingAt === null) {
            return true;
        }

        return $pendingAt->copy()
            ->addSeconds((int) config('security.two_factor.pending_ttl_seconds'))
            ->isPast();
    }

    private function clearPendingEnrollment(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_pending_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_timestep' => null,
        ])->save();
    }

    /**
     * Turn two-factor off, behind the same fresh password check as
     * enrollment. Every trace of the enrollment is cleared.
     */
    public function disable(User $user, string $currentPassword): void
    {
        $this->assertCurrentPassword($user, $currentPassword);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_pending_at' => null,
            'two_factor_last_timestep' => null,
        ])->save();
    }

    /**
     * Verify a TOTP code against the user's stored secret, and
     * consume it: the code's timestep is recorded, and any code at
     * or before the last consumed timestep is refused from then on
     * (finding N-1; RFC 6238 Section 5.2 forbids accepting the same
     * code twice). This is the single verification routine behind
     * the enrollment confirmation, the sign-in challenge, and the
     * step-up challenge, so consumption is shared across all three:
     * a code spent in one can never be replayed in another.
     */
    public function verifyCode(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $timestep = Totp::matchingTimestep($secret, $code);

        if ($timestep === null) {
            return false;
        }

        // Conditional update: the consume succeeds only if this
        // timestep is newer than the one on record, so two requests
        // racing the same code cannot both spend it.
        $consumed = User::query()
            ->whereKey($user->id)
            ->where(function ($query) use ($timestep) {
                $query->whereNull('two_factor_last_timestep')
                    ->orWhere('two_factor_last_timestep', '<', $timestep);
            })
            ->update(['two_factor_last_timestep' => $timestep]);

        if ($consumed !== 1) {
            return false;
        }

        $user->two_factor_last_timestep = $timestep;

        return true;
    }

    /**
     * Consume one recovery code: a presented code that matches a
     * stored hash removes that hash, so it can never work twice.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $hashes = $user->two_factor_recovery_codes;

        if (! is_array($hashes) || $hashes === []) {
            return false;
        }

        $presented = $this->recoveryCodeHash($code);

        foreach ($hashes as $index => $stored) {
            if (is_string($stored) && hash_equals($stored, $presented)) {
                unset($hashes[$index]);

                $user->forceFill([
                    'two_factor_recovery_codes' => array_values($hashes),
                ])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * The sign-in and step-up challenge accepts either a current TOTP
     * code or an unused recovery code.
     *
     * @return 'totp'|'recovery'|null
     */
    public function verifyChallenge(User $user, string $input): ?string
    {
        if ($this->verifyCode($user, $input)) {
            return 'totp';
        }

        if ($this->consumeRecoveryCode($user, $input)) {
            return 'recovery';
        }

        return null;
    }

    /**
     * The otpauth provisioning URI an authenticator app scans,
     * carrying the parameters matching Totp (SHA-1, 6 digits, 30
     * seconds).
     */
    public function provisioningUri(User $user): string
    {
        $issuer = (string) config('app.name');

        return 'otpauth://totp/'.rawurlencode($issuer.': '.$user->email)
            .'?secret='.(string) $user->two_factor_secret
            .'&issuer='.rawurlencode($issuer)
            .'&algorithm=SHA1&digits=6&period=30';
    }

    private function assertCurrentPassword(User $user, string $currentPassword): void
    {
        if (! password_verify($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => trans('auth.password'),
            ]);
        }
    }

    private function formatRecoveryCode(string $raw): string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $raw));

        return substr($clean, 0, 5).'-'.substr($clean, 5, 5);
    }

    private function recoveryCodeHash(string $code): string
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));

        return hash('sha256', $normalized);
    }
}
