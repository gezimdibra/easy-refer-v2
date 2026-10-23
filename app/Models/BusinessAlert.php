<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An owner alert record (spec Section 3 Step 11b). The unique
 * (business, kind) pair makes first-visit alerts idempotent: claim
 * the row first, notify only when the claim was yours.
 */
class BusinessAlert extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'kind',
        'notification_state',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
