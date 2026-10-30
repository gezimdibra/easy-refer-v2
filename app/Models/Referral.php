<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A referral (spec Section 7.2): a friend's pending visit, created
 * only after the friend's phone verification (spec Section 3 Step
 * 8). The lifecycle state and the review hold are separate axes;
 * expiry is applied by the state machine at every read and
 * transition, and by the expiry sweeper, never left to chance.
 */
class Referral extends Model
{
    public const STATE_PENDING = 'pending_redemption';

    public const STATE_REDEEMED = 'redeemed';

    public const STATE_EXPIRED = 'expired';

    public const STATE_VOIDED = 'voided';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'source',
        'referrer_membership_id',
        'referred_customer_id',
        'redeem_token_hash',
        'redeem_token_encrypted',
        'friend_reward_text_snapshot',
        'state',
        'hold',
        'hold_rules',
        'held_at',
        'hold_resolved_at',
        'hold_resolved_by',
        'risk_score',
        'risk_rules',
        'pair_superseded_at',
        'redeemed_at',
        'redeemed_by_user_id',
        'attribution_state',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hold' => 'boolean',
            'hold_rules' => 'array',
            'risk_rules' => 'array',
            'risk_score' => 'integer',
            // Sealed copy for re-presentation (see the migration).
            'redeem_token_encrypted' => 'encrypted',
            'held_at' => 'datetime',
            'hold_resolved_at' => 'datetime',
            'pair_superseded_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function referrerMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'referrer_membership_id');
    }

    public function referredCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_customer_id');
    }

    /**
     * F-UP-1 (truth sweep 2026-10-29 PM, Known Item 1): the
     * friend reward text this referral SNAPSHOTTED at claim
     * creation (EnrollmentService::completeClaim is the column's
     * only writer), trimmed, or null when no snapshot was saved.
     */
    public function friendRewardSnapshotText(): ?string
    {
        $text = trim((string) $this->friend_reward_text_snapshot);

        return $text === '' ? null : $text;
    }

    /**
     * Whether this referral's friend reward is HISTORY: the
     * visit completed, or the window closed without one
     * (expired / voided). Historical claims render their
     * snapshot; a claim still in flight (pending) is not
     * history and its record surfaces keep rendering live
     * config, exactly as before F-UP-1.
     */
    public function friendRewardIsHistorical(): bool
    {
        return in_array($this->state, [self::STATE_REDEEMED, self::STATE_EXPIRED, self::STATE_VOIDED], true);
    }

    /**
     * The friend reward text a historical or counter surface
     * must render for this referral: the claim-time snapshot
     * when one was saved. When the snapshot is null the row
     * predates the snapshot column (or was minted outside the
     * claim flow) and there is no other truth for it, so this
     * method applies the LEGACY LIVE-CONFIG FALLBACK: it reads
     * the business's CURRENT reward text (Business::rewardText()).
     * The fallback is deliberate and named here (and in the
     * F-UP-1 build report); it is never a silent second source.
     */
    public function promisedFriendRewardText(): ?string
    {
        return $this->friendRewardSnapshotText() ?? $this->business?->rewardText();
    }

    public function reward(): HasOne
    {
        return $this->hasOne(Reward::class);
    }

    public function billingEvent(): HasOne
    {
        return $this->hasOne(BillingEvent::class);
    }

    /**
     * Apply the expiry rule on read (spec Section 7.2): a pending
     * referral whose window has closed is expired, flipped here with
     * a state-guarded update so a concurrent redemption wins or
     * loses exactly once (spec Section 7.4 rule 2, CONC-02). Returns
     * true when this call performed the flip.
     */
    /**
     * Set-level expiry for one business, so list and dashboard
     * reads see post-transition states (spec Section 7.2: expiry
     * is applied by the state machine at every read).
     */
    public static function applyExpiryForBusiness(Business $business): void
    {
        static::query()
            ->where('business_id', $business->id)
            ->where('state', self::STATE_PENDING)
            ->where('expires_at', '<=', now())
            ->chunkById(200, function ($referrals) {
                foreach ($referrals as $referral) {
                    $referral->applyExpiry();
                }
            });
    }

    public function applyExpiry(): bool
    {
        // SA-03 (coordinator ruling, Amendment 35): a referral
        // held for owner review does not expire. The hold is the
        // owner's own pause, so the visit clock cannot run out
        // silently underneath it; on release, releaseReferral
        // restores at least the fresh minimum window. Expiry
        // creates no billing event and touches no money state
        // (billing events are minted only at redemption), so
        // holding the clock changes nothing on the money side.
        if ($this->hold) {
            return false;
        }

        if ($this->state !== self::STATE_PENDING || $this->expires_at === null || $this->expires_at->isFuture()) {
            return false;
        }

        $flipped = static::query()
            ->where('id', $this->id)
            ->where('state', self::STATE_PENDING)
            ->update(['state' => self::STATE_EXPIRED]);

        if ($flipped === 1) {
            $this->state = self::STATE_EXPIRED;

            return true;
        }

        $this->refresh();

        return false;
    }
}
