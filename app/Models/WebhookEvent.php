<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A webhook delivery, deduped on (provider, event_id) (spec
 * Sections 7.2, 9.4). Stores the event ID, type, and a redacted
 * summary only; full payloads are never retained.
 */
class WebhookEvent extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'signature_verified',
        'processed_at',
        'payload_summary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signature_verified' => 'boolean',
            'processed_at' => 'datetime',
            'payload_summary' => 'array',
        ];
    }
}
