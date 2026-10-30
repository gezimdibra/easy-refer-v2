<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One §9.2 funnel event for a claimable page: the event name and a
 * timestamp, keyed by page id. Counts and timestamps only — no
 * visitor identity, no customer data, nothing about the shop
 * beyond its register row (design pass §9.2).
 */
class ClaimableEvent extends Model
{
    public const UPDATED_AT = null;

    /** The §9.2 funnel vocabulary, closed by construction. */
    public const EVENTS = [
        'generated', 'sent', 'opened', 'demo_started', 'demo_completed',
        'claim_started', 'gate_code_sent', 'gate_passed', 'review_requested',
        'claimed', 'removed', 'restored', 'expired', 'killed', 'contested',
        'claim_blocked_removed',
    ];

    protected $fillable = ['claimable_page_id', 'event', 'meta', 'created_at'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(ClaimablePage::class, 'claimable_page_id');
    }

    public static function record(ClaimablePage $page, string $event, ?array $meta = null): self
    {
        abort_unless(in_array($event, self::EVENTS, true), 500, 'Unknown claimable event.');

        return static::create([
            'claimable_page_id' => $page->id,
            'event' => $event,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }
}
