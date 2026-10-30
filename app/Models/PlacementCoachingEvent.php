<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One placement-coaching record (ID-04, design pass Sections
 * 5.3 and 7.1): an impression of the coaching card (at most one
 * per business per fingerprint per business-local day) or a
 * dismissal of it. Append-only in application code: rows are
 * the measurement log and the suppression history, never edited.
 * Business-level only; no customer data is recorded here.
 */
class PlacementCoachingEvent extends Model
{
    public const KIND_IMPRESSION = 'impression';

    public const KIND_DISMISSED = 'dismissed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'kind',
        'fingerprint',
        'worst_placement',
        'best_placement',
        'move_key',
        'gap',
        'best_scans',
        'worst_scans',
        'window_days',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gap' => 'integer',
            'best_scans' => 'integer',
            'worst_scans' => 'integer',
            'window_days' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
