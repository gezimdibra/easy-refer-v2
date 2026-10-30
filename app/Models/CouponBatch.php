<?php

namespace App\Models;

use App\Support\OpaqueToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A coupon batch (ID-11, S3; design pass Section 5.1): one QR
 * and short link the owner prints and places outside the store.
 * The batch is the placement identity for source=coupon, in the
 * ID-04 placement family: its label names where the owner put
 * it, and its counts are derived from the scan events and claim
 * rows themselves (Section 5.3), never from a stored counter.
 *
 * Status is a stored axis (active, full, killed) plus a time
 * axis: an active batch whose expires_at has passed reads as
 * expired, applied on read exactly like referral expiry.
 */
class CouponBatch extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_FULL = 'full';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_KILLED = 'killed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'created_by',
        'label',
        'offer_text',
        'claim_cap',
        'token_hash',
        'token_encrypted',
        'status',
        'expires_at',
        'flagged_at',
        'flag_cleared_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'claim_cap' => 'integer',
            // Sealed copy for re-presentation on the owner's
            // Coupons tab and flyer (the QrCode pattern).
            'token_encrypted' => 'encrypted',
            'expires_at' => 'datetime',
            'flagged_at' => 'datetime',
            'flag_cleared_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(CouponClaim::class, 'batch_id');
    }

    /** The plaintext batch token for owner-facing rendering. */
    public function plainToken(): ?string
    {
        return $this->token_encrypted;
    }

    /** Resolve a presented batch token, or null. */
    public static function byToken(string $plainToken): ?static
    {
        if (! OpaqueToken::isWellFormed($plainToken)) {
            return null;
        }

        return static::query()->where('token_hash', OpaqueToken::hash($plainToken))->first();
    }

    /**
     * Apply the time axis on read: an active batch past its
     * expires_at is expired. State-guarded, so a kill or a cap
     * flip in flight wins exactly once. Returns true when this
     * call performed the flip.
     */
    public function applyExpiry(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE || $this->expires_at === null || $this->expires_at->isFuture()) {
            return false;
        }

        $flipped = static::query()
            ->where('id', $this->id)
            ->where('status', self::STATUS_ACTIVE)
            ->where('expires_at', '<=', now())
            ->update(['status' => self::STATUS_EXPIRED]);

        if ($flipped === 1) {
            $this->status = self::STATUS_EXPIRED;

            return true;
        }

        $this->refresh();

        return false;
    }

    /** The status a reader should act on, time axis applied. */
    public function effectiveStatus(): string
    {
        $this->applyExpiry();

        return $this->status;
    }

    public function isLive(): bool
    {
        return $this->effectiveStatus() === self::STATUS_ACTIVE;
    }

    /** Derived count: claims minted from this batch (all states). */
    public function claimsMinted(): int
    {
        return $this->claims()->count();
    }

    /** Derived count: claims redeemed at the counter. */
    public function claimsRedeemed(): int
    {
        return $this->claims()->where('state', CouponClaim::STATE_REDEEMED)->count();
    }

    /**
     * Derived count: resolved landings (pass Section 5.2: a
     * coupon scan is a resolved landing, recorded as a
     * coupon_scan event whose metadata carries the batch).
     */
    public function scansCount(): int
    {
        return ReferralEvent::query()
            ->where('business_id', $this->business_id)
            ->where('event_type', 'coupon_scan')
            ->where('metadata->batch_id', $this->id)
            ->count();
    }

    /**
     * Whether the batch currently carries an uncleared flag
     * (state C11): a flag the owner has not since marked
     * "Looks fine". A flag changes nothing for any customer.
     */
    public function isFlagged(): bool
    {
        return $this->flagged_at !== null
            && ($this->flag_cleared_at === null || $this->flagged_at->gt($this->flag_cleared_at));
    }
}
