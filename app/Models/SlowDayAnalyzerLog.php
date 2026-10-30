<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded run of the dormant slow-day floor computation
 * (ID-01 section 1.3, Amendment 46). Record-only in this
 * build: no surface reads this table, no prompt input comes
 * from it. It exists so the post-launch data candidate's
 * population is known from data when it is considered.
 */
class SlowDayAnalyzerLog extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'candidate_weekday',
        'gates',
        'totals',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'candidate_weekday' => 'integer',
            'gates' => 'array',
            'totals' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
