<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit record (spec Section 7.2). Application code writes
 * rows through AuditLog::write() and never updates or deletes them.
 * Metadata must never contain passwords, tokens, or secrets (protocol
 * Section 5.9).
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'user_id',
        'action',
        'target_type',
        'target_id',
        'ip_address',
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

    /**
     * Immutability is enforced, not conventional (spec Section 7.2):
     * audit rows are written once and never updated or deleted through
     * the application.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log rows are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit log rows are immutable.'));
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Write one audit row for an auth or mutation event. The target is
     * the record the event is about when it differs from the actor (for
     * example a failed login, where there is no authenticated actor but
     * there is a target account).
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function write(string $action, ?User $actor = null, array $metadata = [], ?Business $business = null, ?Model $target = null): static
    {
        return static::create([
            'business_id' => $business?->id ?? $actor?->business_id ?? ($target instanceof User ? $target->business_id : null),
            'user_id' => $actor?->id,
            'action' => $action,
            'target_type' => $target?->getMorphClass(),
            'target_id' => $target?->getKey(),
            'ip_address' => app()->bound('request') ? request()->ip() : null,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
