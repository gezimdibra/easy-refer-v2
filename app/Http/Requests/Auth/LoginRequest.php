<?php

namespace App\Http\Requests\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AccountUnlockNotification;
use App\Notifications\LoginAttemptAlertNotification;
use App\Services\Auth\AccountUnlockService;
use App\Support\CanonicalEmail;
use App\Support\DummyHash;
use App\Support\PhoneNumber;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owner login with one identifier field accepting email or phone (spec
 * Section 4.2), persistent server-side throttling with escalating
 * lockout, and enumeration-resistant failures (spec Section 8.3).
 *
 * Every identifier is canonicalized BEFORE any lookup, limit, or
 * constraint (spec Section 3.1): format variants of one identity share
 * one throttle budget. Failures against one account are additionally
 * counted across all source IPs (the aggregate defense, threat G-06);
 * crossing that threshold locks the account itself, alerts the owner,
 * and requires a server-verified Turnstile challenge, when configured,
 * to sign in during the lock.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Canonical form of a login identifier: canonical email for email
     * input, E.164 for phone input, lowercased raw string otherwise.
     */
    public static function canonicalIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        if (str_contains($identifier, '@')) {
            return CanonicalEmail::of($identifier);
        }

        return PhoneNumber::normalizeToE164($identifier) ?? Str::lower($identifier);
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $identifier = trim((string) $this->input('identifier'));
        $user = $this->findUser($identifier);

        $this->ensureIsNotRateLimited($identifier, $user);

        $plain = (string) $this->input('password');

        // password_verify is algorithm-agnostic: a legacy or unexpected
        // stored hash returns false here instead of throwing, which the
        // configured Argon2id hasher does by design. New hashes are still
        // only ever written by the Argon2id hasher (model cast).
        if ($user === null) {
            password_verify($plain, DummyHash::value());
            $this->registerFailure($identifier, null);
        }

        if (! password_verify($plain, $user->password)) {
            $this->registerFailure($identifier, $user);
        }

        RateLimiter::clear($this->throttleKey($identifier));
        RateLimiter::clear($this->accountFailuresKey($identifier));
        RateLimiter::clear($this->accountLockKey($identifier));

        Auth::login($user, false);

        // Argon2id parameter upgrades land transparently on login
        // (config/hashing.php, spec Section 8.2).
        if (password_needs_rehash($user->password, PASSWORD_ARGON2ID, [
            'memory_cost' => (int) config('hashing.argon.memory'),
            'time_cost' => (int) config('hashing.argon.time'),
            'threads' => (int) config('hashing.argon.threads'),
        ])) {
            $user->password = $plain;
            $user->save();
        }
    }

    private function findUser(string $identifier): ?User
    {
        if (str_contains($identifier, '@')) {
            return User::query()
                ->where('canonical_email', CanonicalEmail::of($identifier))
                ->first();
        }

        $phone = PhoneNumber::normalizeToE164($identifier);

        if ($phone === null) {
            return null;
        }

        return User::query()->where('phone_e164', $phone)->first();
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(string $identifier, ?User $user): void
    {
        if ($user !== null && RateLimiter::attempts($this->accountLockKey($identifier)) > 0) {
            if (! $this->turnstileChallengeSatisfied()) {
                event(new Lockout($this));

                throw ValidationException::withMessages([
                    'identifier' => $this->throttleMessage($this->accountLockKey($identifier)),
                ]);
            }
        }

        $escalatedKey = $this->escalationKey($identifier);

        if (RateLimiter::attempts($escalatedKey) > 0) {
            event(new Lockout($this));

            throw ValidationException::withMessages([
                'identifier' => $this->throttleMessage($escalatedKey),
            ]);
        }

        $key = $this->throttleKey($identifier);

        if (RateLimiter::tooManyAttempts($key, (int) config('security.login_throttle.max_attempts'))) {
            event(new Lockout($this));

            throw ValidationException::withMessages([
                'identifier' => $this->throttleMessage($key),
            ]);
        }
    }

    /**
     * Record a failed attempt and throw the failure response. The
     * attempt that exhausts the budget answers with the lockout message;
     * every other failure answers with the generic credential message,
     * identical for unknown identifiers and wrong passwords. Failures
     * and lockouts are audit-logged (spec Section 8.8) with a hash of
     * the canonical identifier, never the identifier itself.
     *
     * @throws ValidationException
     */
    private function registerFailure(string $identifier, ?User $user): never
    {
        AuditLog::write('owner.login_failed', null, [
            'identifier_hash' => hash('sha256', self::canonicalIdentifier($identifier)),
        ], $user?->business, $user);

        if ($user !== null) {
            $this->registerAccountFailure($identifier, $user);
        }

        $key = $this->throttleKey($identifier);

        RateLimiter::hit($key, (int) config('security.login_throttle.decay_seconds'));

        if (RateLimiter::tooManyAttempts($key, (int) config('security.login_throttle.max_attempts'))) {
            $this->registerLockout($identifier);
            event(new Lockout($this));

            throw ValidationException::withMessages([
                'identifier' => $this->throttleMessage($key),
            ]);
        }

        throw ValidationException::withMessages([
            'identifier' => trans('auth.failed'),
        ]);
    }

    /**
     * The aggregate defense: count this failure against the account
     * across all source IPs; at the threshold, lock the account and
     * alert the owner exactly once per crossing.
     */
    private function registerAccountFailure(string $identifier, User $user): void
    {
        $failures = RateLimiter::hit(
            $this->accountFailuresKey($identifier),
            (int) config('security.login_throttle.account_window_seconds')
        );

        if ($failures >= (int) config('security.login_throttle.account_max_failures')) {
            RateLimiter::hit(
                $this->accountLockKey($identifier),
                (int) config('security.login_throttle.account_lock_seconds')
            );

            if ($failures === (int) config('security.login_throttle.account_max_failures')) {
                $user->notify(new LoginAttemptAlertNotification($failures));

                // Amendment 5: the owner also receives a one-time
                // unlock link, so the lock can never strand the
                // legitimate owner, Turnstile keys or not (finding
                // M-1). The link clears this aggregate lock only.
                $user->notify(new AccountUnlockNotification(
                    app(AccountUnlockService::class)->issue($user, $identifier)
                ));

                AuditLog::write('owner.login_lockout', null, [
                    'failure_count' => $failures,
                ], $user->business, $user);
            }
        }
    }

    private function registerLockout(string $identifier): void
    {
        $lockouts = RateLimiter::hit(
            $this->lockoutKey(),
            (int) config('security.login_throttle.lockout_tracking_seconds')
        );

        if ($lockouts >= (int) config('security.login_throttle.lockouts_before_escalation')) {
            RateLimiter::hit(
                $this->escalationKey($identifier),
                (int) config('security.login_throttle.escalated_decay_seconds')
            );
        }
    }

    /**
     * Server-side verification of the Turnstile challenge (spec Section
     * 8.3). Only meaningful while the account lock is active; without
     * configured keys there is no challenge, and the owner's emailed
     * unlock link (Amendment 5) is the way through instead.
     */
    private function turnstileChallengeSatisfied(): bool
    {
        $secret = (string) config('services.turnstile.secret_key');
        $token = (string) $this->input('cf-turnstile-response', '');

        if ($secret === '' || $token === '') {
            return false;
        }

        $response = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => (string) $this->ip(),
        ]);

        return $response->json('success') === true;
    }

    private function throttleMessage(string $key): string
    {
        $seconds = RateLimiter::availableIn($key);

        return trans('auth.throttle', [
            'seconds' => $seconds,
            'minutes' => (int) ceil($seconds / 60),
        ]);
    }

    public function throttleKey(string $identifier): string
    {
        return static::throttleKeyFor($identifier, (string) $this->ip());
    }

    public static function throttleKeyFor(string $identifier, string $ip): string
    {
        return 'login-attempts:'.hash('sha256', self::canonicalIdentifier($identifier).'|'.$ip);
    }

    public static function accountFailuresKeyFor(string $identifier): string
    {
        return 'login-account-failures:'.hash('sha256', self::canonicalIdentifier($identifier));
    }

    public static function accountLockKeyFor(string $identifier): string
    {
        return 'login-account-lock:'.hash('sha256', self::canonicalIdentifier($identifier));
    }

    public function accountFailuresKey(string $identifier): string
    {
        return static::accountFailuresKeyFor($identifier);
    }

    public function accountLockKey(string $identifier): string
    {
        return static::accountLockKeyFor($identifier);
    }

    public function lockoutKey(): string
    {
        return 'login-lockouts:'.hash('sha256', (string) $this->ip());
    }

    public function escalationKey(string $identifier): string
    {
        return 'login-escalated:'.hash('sha256', self::canonicalIdentifier($identifier).'|'.(string) $this->ip());
    }
}
