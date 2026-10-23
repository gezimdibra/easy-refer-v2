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
     * Apply the vesting transition on read: a pending_vesting reward
     * whose hold has elapsed is available. State-guarded, so the flip
     * happens exactly once however many readers race it.
     */
    public function applyVesting(): bool
    {
        if ($this->state !== self::STATE_PENDING_VESTING || $this->available_at->isFuture()) {
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
