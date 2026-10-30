<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ID-06 shown stamp (Amendment 49; design Section 8.1
 * item 4): at most one row per business per business-local
 * day the second-placement row actually rendered on the
 * Action Center page, following ID-04's impression pattern.
 * Badge and dashboard reads render no row and stamp nothing,
 * so shown-before-print attribution in the readout is
 * measured, never assumed.
 */
class SecondPlacementPromptImpression extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'trigger_referral_id',
        'offered_placement',
        'shown_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shown_on' => 'date',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
