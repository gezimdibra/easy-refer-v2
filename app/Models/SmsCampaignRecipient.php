<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recipient of a campaign (spec Section 7.2): the
 * eligibility evidence (the membership), the phone snapshot at
 * queue time, the delivery state as the send job and the status
 * callbacks advance it, and the metering claim: segments and
 * amount are fixed at queue time, and the monthly charger claims
 * the row (billing_record_id) only when the send was attempted,
 * which its sms_messages evidence link proves.
 */
class SmsCampaignRecipient extends Model
{
    public const STATE_QUEUED = 'queued';

    public const STATE_SENT = 'sent';

    public const STATE_DELIVERED = 'delivered';

    public const STATE_FAILED = 'failed';

    public const STATE_UNDELIVERED = 'undelivered';

    public const STATE_SKIPPED = 'skipped';

    public const STATE_NOT_SENT = 'not_sent';

    public const SKIP_OPTED_OUT = 'opted_out';

    public const SKIP_CONSENT_REVOKED = 'consent_revoked';

    public const SKIP_MEMBERSHIP_REMOVED = 'membership_removed';

    public const SKIP_DAILY_CAP = 'daily_cap';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'campaign_id',
        'membership_id',
        'customer_id',
        'phone_e164',
        'twilio_message_sid',
        'sms_message_id',
        'delivery_state',
        'skip_reason',
        'provider_error_code',
        'segments',
        'amount_cents',
        'billing_record_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'segments' => 'integer',
            'amount_cents' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SmsCampaign::class, 'campaign_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function smsMessage(): BelongsTo
    {
        return $this->belongsTo(SmsMessage::class, 'sms_message_id');
    }

    public function billingRecord(): BelongsTo
    {
        return $this->belongsTo(BillingRecord::class, 'billing_record_id');
    }
}
