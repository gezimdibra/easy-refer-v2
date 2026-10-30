<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A referrer's earned reward (spec Section 7.2). Minted into
 * pending_vesting at redemption (OD-3, option (b)); the text is the
 * mint-time snapshot, so later settings edits never rewrite what a
 * customer was promised (EC-35).
 */
class Reward extends Model
{
    public const STATE_PENDING_VESTING = 'pending_vesting';

    public const STATE_AVAILABLE = 'available';

    public const STATE_CLAIMED = 'claimed';

    public const STATE_DENIED = 'denied';

    public const STATE_EXPIRED = 'expired';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'referral_id',
        'membership_id',
        'reward_text_snapshot',
        'state',
        'hold',
        'hold_rules',
        'held_at',
        'hold_resolved_at',
        'hold_resolved_by',
        'claim_token_hash',
        'claim_token_encrypted',
        'available_at',
        'expires_at',
        'expiry_notice_sent_at',
        'claimed_at',
        'claimed_by_user_id',
        'notification_state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hold' => 'boolean',
            'hold_rules' => 'array',
            // Sealed copy for re-presentation (see the migration).
            'claim_token_encrypted' => 'encrypted',
            'held_at' => 'datetime',
            'hold_resolved_at' => 'datetime',
            'available_at' => 'datetime',
            'expires_at' => 'datetime',
            'expiry_notice_sent_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * Ready to claim, the ONE derivation (FLOW-STATE TRUTH
     * train): state available AND not held — exactly the
     * Rewards page's "Ready to claim" bucket. The dashboard's
     * "Rewards waiting to be claimed" tile reads through this
     * scope, so a held reward can never count as claimable on
     * one surface while the Rewards page buckets it Held.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Reward>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Reward>
     */
    public function scopeReadyToClaim($query)
    {
        return $query->where('state', self::STATE_AVAILABLE)->where('hold', false);
    }

    /**
     * Apply the closure wind-down end on read (spec Section 5.5,
     * RDM-29): an unclaimed reward of a business whose 30-day
     * closure window has run out expires. State-guarded like the
     * other on-read transitions.
     */
    public function applyClosureExpiry(): bool
    {
        if (! in_array($this->state, [self::STATE_AVAILABLE, self::STATE_PENDING_VESTING], true)) {
            return false;
        }

        $business = $this->business;

        if ($business === null || ! $business->closureWindowEnded()) {
            return false;
        }

        $flipped = static::query()
            ->where('id', $this->id)
            ->whereIn('state', [self::STATE_AVAILABLE, self::STATE_PENDING_VESTING])
            ->update(['state' => self::STATE_EXPIRED]);

        if ($flipped === 1) {
            $this->state = self::STATE_EXPIRED;

            return true;
        }

        $this->refresh();

        return false;
    }

    /**
     * Set-level closure expiry for one business (truth sweep
     * 2026-10-30, item 3), mirroring applyVestingForBusiness():
     * the dashboard tile and the Rewards page counted through
     * readyToClaim() after applying VESTING at set level, but
     * closure expiry was applied only per-instance on claim
     * paths — so past closureWindowEnded() both owner surfaces
     * counted an available reward as ready to claim while a
     * claim read would have expired it first. Chunked
     * per-instance applyClosureExpiry(), so counts, list, and
     * claim read the same post-transition state. No scope
     * change, no new predicate: the gate stays the per-instance
     * closureWindowEnded() check, and inside the window this
     * returns at once.
     */
    public static function applyClosureExpiryForBusiness(Business $business): void
    {
        if (! $business->closureWindowEnded()) {
            return;
        }

        static::query()
            ->where('business_id', $business->id)
            ->whereIn('state', [self::STATE_AVAILABLE, self::STATE_PENDING_VESTING])
            ->chunkById(200, function ($rewards) {
                foreach ($rewards as $reward) {
                    $reward->applyClosureExpiry();
                }
            });
    }

    /**
     * Apply the M-4 time expiry on read: an AVAILABLE, unheld
     * reward at or past its stamped expires_at (available_at +
     * the platform reward_expiry_months, migration 000045) flips
     * to expired. The guards are the scope, exactly (design pass
     * Sections 1.2, 2.3, 2.7):
     *  - state available only: pending_vesting never time-flips
     *    (its clock has not started), claimed and denied are
     *    terminal and untouchable;
     *  - hold false: the clock PAUSES under any hold (the SA-03
     *    shape: the hold is platform or owner process, never
     *    customer delay), and releaseReward tops the date up;
     *  - expires_at null never flips: the F-5 legacy population
     *    has no availability date to anchor a use-by date to.
     * Boundary: at exactly expires_at the reward is expired (the
     * first instant it is no longer good), matching Referral
     * expiry's <= discipline. State-guarded like the other
     * on-read transitions, so the flip happens exactly once
     * however many readers race it. Writes state only: no
     * BillingEvent, coverage, or allowance is ever read or
     * written here (pass Section 4.4 boundary proof).
     */
    public function applyTimeExpiry(): bool
    {
        if ($this->state !== self::STATE_AVAILABLE
            || $this->hold
            || $this->expires_at === null
            || $this->expires_at->isFuture()) {
            return false;
        }

        $flipped = static::query()
            ->where('id', $this->id)
            ->where('state', self::STATE_AVAILABLE)
            ->where('hold', false)
            ->update(['state' => self::STATE_EXPIRED]);

        if ($flipped === 1) {
            $this->state = self::STATE_EXPIRED;

            return true;
        }

        $this->refresh();

        return false;
    }

    /**
     * Set-level time expiry for one business, mirroring
     * applyClosureExpiryForBusiness: chunked per-instance
     * applyTimeExpiry(), so the Rewards page counts, the
     * dashboard tile, and a claim read all see the same
     * post-transition state.
     */
    public static function applyTimeExpiryForBusiness(Business $business): void
    {
        static::query()
            ->where('business_id', $business->id)
            ->where('state', self::STATE_AVAILABLE)
            ->where('hold', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->chunkById(200, function ($rewards) {
                foreach ($rewards as $reward) {
                    $reward->applyTimeExpiry();
                }
            });
    }

    /**
     * Set-level vesting for one business, so list and dashboard
     * reads see post-transition states (spec Section 7.2: expiry
     * and vesting are applied at every read).
     */
    public static function applyVestingForBusiness(Business $business): void
    {
        static::query()
            ->where('business_id', $business->id)
            ->where('state', self::STATE_PENDING_VESTING)
            ->where('available_at', '<=', now())
            ->chunkById(200, function ($rewards) {
                foreach ($rewards as $reward) {
                    $reward->applyVesting();
                }
            });
    }

    /**
     * Apply the vesting transition on read: a pending_vesting reward
     * whose hold has elapsed is available. State-guarded, so the flip
     * happens exactly once however many readers race it.
     */
    public function applyVesting(): bool
    {
        // F-5: a vesting row with no stored timestamp cannot be
        // proven elapsed, so it stays vesting (and renders its
        // configured window) instead of crashing the read.
        if ($this->state !== self::STATE_PENDING_VESTING || $this->available_at === null || $this->available_at->isFuture()) {
            return false;
        }

        $flipped = static::query()
            ->where('id', $this->id)
            ->where('state', self::STATE_PENDING_VESTING)
            ->update(['state' => self::STATE_AVAILABLE]);

        if ($flipped === 1) {
            $this->state = self::STATE_AVAILABLE;

            return true;
        }

        $this->refresh();

        return false;
    }
}
