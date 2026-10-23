<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One consent projection change (spec Section 7.2). Consent state is
 * derived by projection over these append-only rows, never mutated
 * in place; the membership row carries the current projection. The
 * event_type records the kind (granted, revoked,
 * provenance_asserted, confirmed); source and
 * flow record where the change happened.
 */
class ConsentEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'membership_id',
        'customer_id',
        'business_id',
        'event_type',
        'source',
        'consent_wording_version',
        'flow',
        'ip_address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }
}
