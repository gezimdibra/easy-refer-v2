<?php

namespace App\Models;

use App\Support\OpaqueToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's membership at one business (spec Section 7.2). The
 * share token is minted once, at membership creation; re-verification
 * by a returning customer returns it unchanged, and it changes only
 * on revocation or owner-initiated regeneration (spec Section 3.1).
 */
class Membership extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'customer_id',
        'share_token_hash',
        'share_token_encrypted',
        'share_token_state',
        'sms_consent',
        'consent_source',
        'consent_wording_version',
        'consent_updated_at',
        'referral_count',
        'removed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sms_consent' => 'boolean',
            'consent_updated_at' => 'datetime',
            'referral_count' => 'integer',
            'removed_at' => 'datetime',
            // Sealed copy for re-presentation (migration 000018): the
            // lookup hash stays the only indexed form.
            'share_token_encrypted' => 'encrypted',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The share token plaintext for re-presentation on the share
     * page (spec Section 3 Step 5: a returning customer's token is
     * returned unchanged). Null only for rows predating the sealed
     * copy.
     */
    public function plainShareToken(): ?string
    {
        return $this->share_token_encrypted;
    }

    /**
     * Resolve a presented share token to its membership, or null
     * for an unknown or revoked token (the friend landing renders
     * the same generic state either way).
     */
    public static function byShareToken(string $plainToken): ?static
    {
        if (! OpaqueToken::isWellFormed($plainToken)) {
            return null;
        }

        $membership = static::query()
            ->where('share_token_hash', OpaqueToken::hash($plainToken))
            ->first();

        if ($membership === null || $membership->share_token_state !== 'active') {
            return null;
        }

        return $membership;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function referralsMade(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_membership_id');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class);
    }

    /**
     * Find the membership for a (business, customer) pair, or create
     * it with a freshly minted share token. Insert-and-continue on
     * the unique pair (spec Section 7.4 rule 3): the loser of a race
     * re-reads the winner's row and proceeds, so a collision is never
     * a 500 and never a second token. Returns the membership, whether
     * it was created, and the share token plaintext (generated now,
     * or unsealed from the stored copy for a returning customer, so
     * the token a customer holds never changes under them).
     *
     * @return array{0: self, 1: bool, 2: ?string}
     */
    public static function findOrCreateWithToken(Business $business, Customer $customer): array
    {
        $existing = static::query()
            ->where('business_id', $business->id)
            ->where('customer_id', $customer->id)
            ->first();

        if ($existing !== null) {
            return [$existing, false, $existing->share_token_encrypted];
        }

        $plainToken = OpaqueToken::generate();

        try {
            $membership = static::create([
                'business_id' => $business->id,
                'customer_id' => $customer->id,
                'share_token_hash' => OpaqueToken::hash($plainToken),
                'share_token_encrypted' => $plainToken,
                'share_token_state' => 'active',
            ]);

            return [$membership, true, $plainToken];
        } catch (\Illuminate\Database\QueryException) {
            $winner = static::query()
                ->where('business_id', $business->id)
                ->where('customer_id', $customer->id)
                ->firstOrFail();

            return [$winner, false, $winner->share_token_encrypted];
        }
    }
}
