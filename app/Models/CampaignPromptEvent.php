<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event in the shared campaign-prompt log (Idea Train 1,
 * Amendment 46): shown / acted / dismissed / reset / reenabled
 * for one Action Center prompt item of one business, keyed by
 * the business-local ISO week. Both prompt features (ID-01
 * slow-day, ID-03 win-back) read their suppression counters
 * and their measurement joins from this one log; the policies
 * stay per-item.
 */
class CampaignPromptEvent extends Model
{
    public const EVENT_SHOWN = 'shown';

    public const EVENT_ACTED = 'acted';

    public const EVENT_DISMISSED = 'dismissed';

    public const EVENT_RESET = 'reset';

    public const EVENT_REENABLED = 'reenabled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'item_key',
        'week_key',
        'week_serial',
        'event',
        'campaign_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week_serial' => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SmsCampaign::class, 'campaign_id');
    }
}
