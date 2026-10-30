<?php

namespace App\Models;

use App\Support\OpaqueToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A coupon claim (ID-11, S3; design pass Sections 2.2 and 5.1):
 * the coupon itself. One completed phone verification mints one
 * claim carrying its own single-use, 128-bit, state-guarded
 * redemption token, the shipped counter pattern. The claim
 * unlocks IMMEDIATELY at verified scan: there is no gap, no
 * vesting, no hold, no referrer, and no billing event at any
 * point in its life (pass Sections 2.3, 3.2, 4.4).
 */
class CouponClaim extends Model
{
    public const STATE_READY = 'ready';

    public const STATE_REDEEMED = 'redeemed';

    public const STATE_EXPIRED = 'expired';

    public const STATE_VOIDED = 'voided';

    /** Owner void reasons (pass Section 2.2, state C10). */
    public const VOID_REASONS = ['owner_test', 'lost_phone', 'other'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'batch_id',
        'customer_id',
        'source',
        'state',
        'offer_text',
        'redeem_token_hash',
        'redeem_token_encrypted',
        'expires_at',
        'redeemed_at',
        'redeemed_by_user_id',
        'void_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'redeem_token_encrypted' => 'encrypted',
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CouponBatch::class, 'batch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The plaintext redemption token for the customer's page. */
    public function plainToken(): ?string
    {
        return $this->redeem_token_encrypted;
    }

    /** Resolve a presented redemption token, or null. */
    public static function byToken(string $plainToken): ?static
    {
        if (! OpaqueToken::isWellFormed($plainToken)) {
            return null;
        }

        return static::query()->where('redeem_token_hash', OpaqueToken::hash($plainToken))->first();
    }

    /**
     * Apply expiry on read, both clocks (pass Section 2.2 states
     * C9 and the closure leg of C8): a ready claim past its own
     * expires_at is expired, and a ready claim at a business
     * whose 30-day closure window has ended expires at first
     * read past the window (the Reward::applyClosureExpiry
     * pattern). State-guarded, exactly once.
     */
    public function applyExpiry(): bool
    {
        if ($this->state !== self::STATE_READY) {
            return false;
        }

        $ownWindowClosed = $this->expires_at !== null && $this->expires_at->isPast();
        $closureWindowClosed = $this->business !== null && $this->business->closureWindowEnded();

        if (! $ownWindowClosed && ! $closureWindowClosed) {
            return false;
        }

        $flipped = static::query()
            ->where('id', $this->id)
            ->where('state', self::STATE_READY)
            ->update(['state' => self::STATE_EXPIRED]);

        if ($flipped === 1) {
            $this->state = self::STATE_EXPIRED;

            return true;
        }

        $this->refresh();

        return false;
    }
}
