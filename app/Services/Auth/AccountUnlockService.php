<?php

namespace App\Services\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Support\OpaqueToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One-time account unlock links (spec Section 8.3, Amendment 5).
 *
 * The per-account aggregate login lock binds an account by identity
 * alone, so a distributed password spray would otherwise keep the
 * legitimate owner out for the whole lock window in any environment
 * where Turnstile keys are not configured. When the lock trips, this
 * service issues the owner a single-use token: only its SHA-256 hash
 * is stored (spec Section 7.1), it expires with the lock window, and
 * issuing a new token supersedes the previous one. Consuming a token
 * clears the aggregate lock and its failure counter, and nothing
 * else: the per-IP throttles and the escalation state that protect
 * every other identifier stay exactly as they were.
 */
class AccountUnlockService
{
    /**
     * Issue a fresh unlock token for the account and return the
     * plaintext, which exists only in the email that carries it.
     */
    public function issue(User $user, string $identifier): string
    {
        $canonical = LoginRequest::canonicalIdentifier($identifier);
        $plain = OpaqueToken::generate();
        $hash = OpaqueToken::hash($plain);
        $ttl = (int) config('security.account_unlock.token_ttl_seconds');

        $previous = Cache::get($this->userKey($canonical));

        if (is_string($previous)) {
            Cache::forget($this->tokenKey($previous));
        }

        Cache::put($this->tokenKey($hash), [
            'identifier' => $canonical,
            'user_id' => $user->id,
        ], $ttl);
        Cache::put($this->userKey($canonical), $hash, $ttl);

        return $plain;
    }

    /**
     * Consume a presented token. Consumption is one pull: the token
     * can never be used twice, and an unknown or expired token yields
     * null with no hint about which case occurred.
     *
     * @return array{identifier: string, user_id: int}|null
     */
    public function consume(string $plain): ?array
    {
        $hash = OpaqueToken::hash($plain);
        $entry = Cache::pull($this->tokenKey($hash));

        if (! is_array($entry)) {
            return null;
        }

        $userKey = $this->userKey($entry['identifier']);

        if (Cache::get($userKey) === $hash) {
            Cache::forget($userKey);
        }

        return $entry;
    }

    /**
     * Clear the aggregate lock and its failure counter for one
     * identifier. Per-IP state is deliberately not touched here.
     */
    public function clearAggregateLock(string $identifier): void
    {
        RateLimiter::clear(LoginRequest::accountLockKeyFor($identifier));
        RateLimiter::clear(LoginRequest::accountFailuresKeyFor($identifier));
    }

    private function tokenKey(string $hash): string
    {
        return 'account-unlock:token:'.$hash;
    }

    private function userKey(string $canonicalIdentifier): string
    {
        return 'account-unlock:user:'.hash('sha256', $canonicalIdentifier);
    }
}
