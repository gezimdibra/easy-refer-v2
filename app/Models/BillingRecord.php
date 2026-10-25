<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One charge attempt and its outcome (spec Sections 7.2, 9.3).
 * Created in `pending` with its deterministic idempotency key
 * before any Square call; record-only dry runs are `simulated`
 * and never reach the provider boundary.
 */
class BillingRecord extends Model
{
    public const KIND_PER_REFERRAL_BATCH = 'per_referral_batch';

    public const KIND_CAMPAIGN_METERING = 'campaign_metering';

    public const STATE_PENDING = 'pending';

    public const STATE_SUCCEEDED = 'succeeded';

    public const STATE_FAILED = 'failed';

    public const STATE_REFUNDED = 'refunded';

    public const STATE_PARTIALLY_REFUNDED = 'partially_refunded';

    public const STATE_DISPUTED = 'disputed';

    public const STATE_SIMULATED = 'simulated';

    public const STATE_SUPERSEDED = 'superseded';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'kind',
        'amount_cents',
        'currency',
        'idempotency_key',
        'square_payment_id',
        'square_order_id',
        'payment_method_id',
        'state',
        'period_start',
        'period_end',
        'error_code',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BillingEvent::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(BillingRefund::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
