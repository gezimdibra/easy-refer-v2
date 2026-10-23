<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\RememberToken;
use App\Models\User;
use App\Support\OpaqueToken;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Selector/validator remember-me (spec Section 8.4), replacing the
 * framework's single plaintext token: the cookie carries
 * "selector.validator", the database stores the selector plus the
 * SHA-256 hash of the validator, and the validator rotates on every
 * use. A superseded validator presented for a known selector means
 * the cookie was copied, so every token the account holds is revoked
 * and the event is audit-logged. Maximum token age is 30 days.
 */
final class RememberMeService
{
    public const COOKIE_NAME = 'er2_remember';

    private const LIFETIME_MINUTES = 60 * 24 * 30;

    /**
     * Issue a fresh token for the user and return the cookie to send.
     */
    public function issue(User $user): SymfonyCookie
    {
        $selector = bin2hex(random_bytes(9));
        $validator = OpaqueToken::generate();

        RememberToken::create([
            'user_id' => $user->id,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
        ]);

        return $this->cookieFor($selector.'.'.$validator);
    }

    /**
     * Validate a presented cookie value. On success returns the user
     * and the rotated cookie to send back; on any failure returns null.
     * Expired tokens are deleted; a validator mismatch on a known
     * selector revokes all of the account's tokens as suspected theft.
     *
     * @return array{user: User, cookie: SymfonyCookie}|null
     */
    public function consume(?string $value): ?array
    {
        if ($value === null || ! str_contains($value, '.')) {
            return null;
        }

        [$selector, $validator] = explode('.', $value, 2);

        $token = RememberToken::query()->where('selector', $selector)->first();

        if ($token === null) {
            return null;
        }

        if ($token->isExpired()) {
            $token->delete();

            return null;
        }

        if (! hash_equals($token->validator_hash, hash('sha256', $validator))) {
            $user = $token->user;
            $this->revokeAll($user);
            AuditLog::write('owner.remember_token_theft', null, [], $user->business, $user);

            return null;
        }

        $newValidator = OpaqueToken::generate();

        $token->forceFill([
            'validator_hash' => hash('sha256', $newValidator),
            'last_used_at' => now(),
        ])->save();

        return [
            'user' => $token->user,
            'cookie' => $this->cookieFor($selector.'.'.$newValidator),
        ];
    }

    /**
     * Revoke the single token a cookie value names, if it exists.
     */
    public function revokeValue(?string $value): void
    {
        if ($value === null || ! str_contains($value, '.')) {
            return;
        }

        [$selector] = explode('.', $value, 2);

        RememberToken::query()->where('selector', $selector)->delete();
    }

    /**
     * Revoke every remember token the user holds (password change or
     * reset, sign-out-everywhere, theft response).
     */
    public function revokeAll(User $user): void
    {
        RememberToken::query()->where('user_id', $user->id)->delete();
    }

    private function cookieFor(string $value): SymfonyCookie
    {
        return Cookie::make(
            self::COOKIE_NAME,
            $value,
            self::LIFETIME_MINUTES,
            (string) config('session.path'),
            config('session.domain'),
            (bool) config('session.secure'),
            true,
            false,
            (string) config('session.same_site')
        );
    }
}
