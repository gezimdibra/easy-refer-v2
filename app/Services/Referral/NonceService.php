<?php

namespace App\Services\Referral;

use App\Models\TokenNonce;
use App\Support\OpaqueToken;
use Illuminate\Support\Facades\DB;

/**
 * The per-render redemption nonce (spec Section 6.1 item 4): bound
 * to exactly one token (a referral or a reward) and one render,
 * single-use, expiring 30 minutes after the render. Rendering a
 * staff screen invalidates every earlier unused nonce for that
 * token, so at most one live nonce exists per token.
 *
 * Both halves are atomic (security verifier m-1): issue runs its
 * delete and insert in one transaction, and consume is a single
 * conditional UPDATE that succeeds only when exactly one live,
 * unconsumed row matched, so two concurrent POSTs carrying the
 * same nonce cannot both pass.
 */
class NonceService
{
    public function issue(string $subjectType, int $subjectId): string
    {
        return DB::transaction(function () use ($subjectType, $subjectId) {
            TokenNonce::query()
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->whereNull('consumed_at')
                ->delete();

            $plain = OpaqueToken::generate();

            TokenNonce::create([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'nonce_hash' => OpaqueToken::hash($plain),
                'expires_at' => now()->addMinutes(30),
            ]);

            return $plain;
        });
    }

    /**
     * Validate and consume. Any POST consumes its nonce, success or
     * failure, so a failed attempt forces a re-render. A known but
     * spent or expired nonce simply fails the conditional update
     * and returns false.
     */
    public function consume(string $subjectType, int $subjectId, ?string $plain): bool
    {
        if ($plain === null || $plain === '') {
            return false;
        }

        $updated = TokenNonce::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('nonce_hash', OpaqueToken::hash($plain))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['consumed_at' => now()]);

        return $updated === 1;
    }
}
