<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One transactional SMS (spec Section 7.2). Every customer-facing
 * text records its template key, consent basis, and the opt-out
 * state at send time, so any message can be proven consented later.
 */
class SmsMessage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'customer_id',
        'phone_e164',
        'purpose',
        'messaging_service',
        'template_key',
        'rendered_body',
        'twilio_message_sid',
        'delivery_state',
        'consent_basis',
        'opt_out_state_at_send',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opt_out_state_at_send' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
