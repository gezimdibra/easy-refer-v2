<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The §8.5 do-not-generate list: one row per shop-requested
 * removal, keyed by place_id only, 12 months from the removal
 * date. Separate from the operator quiet list by design (§8.5:
 * the two lists must never merge — a quiet-list shop never asked
 * for anything).
 */
class ClaimSuppression extends Model
{
    protected $fillable = [
        'place_id', 'shop_name', 'removed_at', 'suppression_ends_at',
        'removal_count', 'operator_review_flag',
    ];

    protected function casts(): array
    {
        return [
            'removed_at' => 'datetime',
            'suppression_ends_at' => 'datetime',
            'removal_count' => 'integer',
            'operator_review_flag' => 'boolean',
        ];
    }

    public function isActive(): bool
    {
        return now()->lessThan($this->suppression_ends_at);
    }

    public static function activeFor(string $placeId): ?self
    {
        $row = static::query()->where('place_id', $placeId)->first();

        return $row !== null && $row->isActive() ? $row : null;
    }
}
