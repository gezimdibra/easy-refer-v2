<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One owner-composed bulk text send (spec Sections 7.2, 10.3).
 * The body is the owner's text only; the system prefix and the
 * STOP suffix are render-time (MessageCopy::campaign), so what
 * was registered with the carrier is what is sent. The figure
 * columns are the last computed estimate on a draft and the
 * confirmed figures once sent.
 */
class SmsCampaign extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'body',
        'recipient_count',
        'segment_count',
        'metered_cents',
        'not_sent_count',
        'status',
        'created_by',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipient_count' => 'integer',
            'segment_count' => 'integer',
            'metered_cents' => 'integer',
            'not_sent_count' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(SmsCampaignRecipient::class, 'campaign_id');
    }
}
