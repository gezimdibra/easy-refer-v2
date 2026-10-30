<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One claimed or completed lifecycle-email send (M-5 design
 * pass, Section 9.2). The (business_id, send_key) unique
 * index is the once-only claim; see the 000046 migration and
 * LifecycleMailService for the claim, re-read, send, release
 * protocol this row carries.
 */
class LifecycleEmailSend extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'user_id',
        'send_key',
        'sent_at',
        'step_completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'step_completed_at' => 'datetime',
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
