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
    /** The win-back segment scope (ID-03, Amendment 46). */
    public const SEGMENT_LAPSED_REFERRERS = 'lapsed_referrers';

    /** The top-referrer recognition provenance (ID-02, Amendment 48). */
    public const PROVENANCE_TOP_REFERRER = 'top_referrer';

    protected $fillable = [
        'business_id',
        'body',
        'audience_segment',
        'provenance',
        'recipient_membership_id',
        'standings_month',
        'standings_count',
        'standings_form',
        'body_customized',
        'origin',
        'winback_exempt_campaign_id',
        'recipient_count',
        'segment_count',
        'metered_cents',
        'not_sent_count',
        'recent_spacing_skipped_count',
        'recent_cap_skipped_count',
        'winback_cooldown_skipped_count',
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
            'recent_spacing_skipped_count' => 'integer',
            'recent_cap_skipped_count' => 'integer',
            'winback_cooldown_skipped_count' => 'integer',
            'winback_exempt_campaign_id' => 'integer',
            'recipient_membership_id' => 'integer',
            'standings_count' => 'integer',
            'body_customized' => 'boolean',
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

    /** The one person a recognition campaign may send to (ID-02). */
    public function recipientMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'recipient_membership_id');
    }

    public function isRecognition(): bool
    {
        return $this->provenance === self::PROVENANCE_TOP_REFERRER;
    }
}
