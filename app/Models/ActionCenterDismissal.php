<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Action Center dismissal (RK-01): the owner dismissed one
 * aggregated state for one business. The stored fingerprint is
 * the dismissed state's identity; the Action Center compares
 * it against the live state on every read, so a dismissal
 * sticks exactly until the state changes.
 */
class ActionCenterDismissal extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'item_key',
        'state_fingerprint',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
