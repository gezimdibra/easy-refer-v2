<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The demo's signup attribution record (G6 demo Phase 1,
 * Section 8, C-1). Holds ONLY: channel, entry point,
 * completion state, the trade chip if picked, timestamps.
 * No phone, no shop name, no demo activity: the carryover
 * prefills travel in the live demo session and die with it;
 * this record is what survives the 7-day window so a later
 * signup still attributes.
 */
class DemoAttribution extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'token_hash',
        'channel',
        'entry_point',
        'completion_state',
        'at_step',
        'trade_chip',
        'demo_completed',
        'signup_business_id',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'demo_completed' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function signupBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'signup_business_id');
    }
}
