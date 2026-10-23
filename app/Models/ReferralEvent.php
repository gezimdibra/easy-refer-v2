<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The immutable audit and fraud log (spec Section 7.2). Rows are
 * append-only: no update or delete path exists in application code.
 * Metadata carries no PII beyond what the event requires.
 */
class ReferralEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'referral_id',
        'reward_id',
        'membership_id',
        'event_type',
        'actor',
        'ip_address',
        'device_signal_hash',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
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

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * Append one event row.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        string $eventType,
        string $actor,
        ?Business $business = null,
        ?Referral $referral = null,
        ?Reward $reward = null,
        ?Membership $membership = null,
        array $metadata = [],
        ?string $ipAddress = null,
        ?string $deviceSignalHash = null,
    ): self {
        return static::create([
            'business_id' => $business?->id,
            'referral_id' => $referral?->id,
            'reward_id' => $reward?->id,
            'membership_id' => $membership?->id,
            'event_type' => $eventType,
            'actor' => $actor,
            'ip_address' => $ipAddress,
            'device_signal_hash' => $deviceSignalHash,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
