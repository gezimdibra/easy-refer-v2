<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One owner's dismissal of the coupons benefit hint (ID-11;
 * design pass Section 6.2 frequency discipline): the hint shows
 * until the owner creates a first batch or dismisses it, then
 * goes quiet for good. The first-batch leg is derived from the
 * batches themselves; only the dismissal needs storage.
 */
class CouponHintDismissal extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'user_id',
        'dismissed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
