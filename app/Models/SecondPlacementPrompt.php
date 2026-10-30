<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The ID-06 second-code prompt's state record for one
 * business (Amendment 49): one row per business, ever. The
 * row is the measurement ledger and the terminal authority —
 * detection is computed live from the settled predicate, the
 * print records, and the scan events on every read, and this
 * row stamps the transitions (armed at a held first visit,
 * live at first render, and the five terminal outcomes) so
 * the Section 8.2 readout can attribute prints honestly.
 *
 * Terminal states are FINAL (design Section 5.4): acted,
 * dismissed, satisfied, gap-closed, and expired never
 * reopen, whatever later visits do.
 */
class SecondPlacementPrompt extends Model
{
    public const STATE_ARMED = 'armed';

    public const STATE_LIVE = 'live';

    public const STATE_ACTED = 'acted';

    public const STATE_DISMISSED = 'dismissed';

    public const STATE_SATISFIED = 'satisfied';

    public const STATE_GAP_CLOSED = 'gap_closed';

    public const STATE_EXPIRED = 'expired';

    /** @var list<string> */
    public const TERMINAL_STATES = [
        self::STATE_ACTED,
        self::STATE_DISMISSED,
        self::STATE_SATISFIED,
        self::STATE_GAP_CLOSED,
        self::STATE_EXPIRED,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'trigger_referral_id',
        'state',
        'settled_at',
        'offered_placement',
        'acted_placement',
        'acted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settled_at' => 'datetime',
            'acted_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function triggerReferral(): BelongsTo
    {
        return $this->belongsTo(Referral::class, 'trigger_referral_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, self::TERMINAL_STATES, true);
    }
}
