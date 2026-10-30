<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One pre-made claimable shop page (G1 design pass §5). The row is
 * the register record; its snapshot columns hold public listing
 * facts only and are purged at expiry (P7) and removal purge (R2).
 * States: generated (P0), sent (P1/P2), claim in progress is an
 * event, not a status (P3), pending_review (P4), claimed (P5),
 * expired (P7), removed (P8; R0-R4 derive from removed_at /
 * purged_at / the suppression row), killed (P9).
 */
class ClaimablePage extends Model
{
    public const STATUS_GENERATED = 'generated';
    public const STATUS_SENT = 'sent';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REMOVED = 'removed';
    public const STATUS_KILLED = 'killed';

    protected $fillable = [
        'token_hash', 'place_id', 'cohort',
        'shop_name', 'business_type', 'trade_label',
        'street', 'city', 'state', 'postal_code',
        'listed_phone_e164', 'opening_hours', 'photo_ref',
        'starter_reward_text', 'snapshot_fetched_at',
        'status', 'sent_at', 'expires_at',
        'claimed_business_id', 'claimed_at',
        'removed_at', 'removal_source', 'status_at_removal',
        'claim_in_flight_at_removal', 'purged_at',
        'removal_count', 'operator_review_flag', 'kill_reason',
    ];

    protected function casts(): array
    {
        return [
            'opening_hours' => 'array',
            'snapshot_fetched_at' => 'datetime',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'removed_at' => 'datetime',
            'purged_at' => 'datetime',
            'claim_in_flight_at_removal' => 'boolean',
            'operator_review_flag' => 'boolean',
            'removal_count' => 'integer',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(ClaimableEvent::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ClaimReview::class);
    }

    public function claimedBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'claimed_business_id');
    }

    public function recordEvent(string $event, ?array $meta = null): ClaimableEvent
    {
        return ClaimableEvent::record($this, $event, $meta);
    }

    /** Unclaimed and openable: the full page may render (P1/P2/P3). */
    public function isOpenable(): bool
    {
        return in_array($this->status, [self::STATUS_GENERATED, self::STATUS_SENT], true);
    }

    public function isExpiredByClock(): bool
    {
        return $this->expires_at !== null && now()->greaterThanOrEqualTo($this->expires_at);
    }

    /** §8.2 R1: inside the 7-day restore window after a removal. */
    public function inRestoreWindow(): bool
    {
        return $this->status === self::STATUS_REMOVED
            && $this->removed_at !== null
            && $this->purged_at === null
            && now()->lessThan($this->removed_at->copy()->addDays((int) config('claimable.restore_window_days')));
    }

    /** Drop every snapshot field (P7 expiry purge / R2 removal purge). */
    public function purgeSnapshot(): void
    {
        $this->forceFill([
            'street' => null, 'city' => null, 'state' => null, 'postal_code' => null,
            'listed_phone_e164' => null, 'opening_hours' => null, 'photo_ref' => null,
            'starter_reward_text' => null, 'business_type' => null, 'trade_label' => null,
            'purged_at' => now(),
        ])->save();
    }

    public function snapshotAddressLine(): string
    {
        return collect([$this->street, $this->city, $this->state, $this->postal_code])
            ->filter(fn ($v) => is_string($v) && $v !== '')->implode(', ');
    }

    public function listedPhoneLastTwo(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->listed_phone_e164);

        return strlen($digits) >= 2 ? substr($digits, -2) : null;
    }
}
