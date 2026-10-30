<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card on file, mirrored locally (spec Section 7.2, blueprint
 * Section 1.1). Brand, last4, and expiry only: the server never
 * sees, logs, or stores a card number.
 */
class PaymentMethod extends Model
{
    public const STATE_PENDING = 'pending';

    public const STATE_SUCCEEDED = 'succeeded';

    public const STATE_FAILED = 'failed';

    public const STATE_REMOVED = 'removed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'square_card_id',
        'card_brand',
        'last4',
        'exp_month',
        'exp_year',
        'is_default',
        'state',
        'idempotency_key',
        'error_code',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exp_month' => 'integer',
            'exp_year' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The card the next charge for this business uses: the one
     * succeeded default, if any.
     */
    public static function defaultFor(Business $business): ?self
    {
        return static::query()
            ->where('business_id', $business->id)
            ->where('state', self::STATE_SUCCEEDED)
            ->where('is_default', true)
            ->first();
    }

    /**
     * The one card-state definition, shared by the Billing page
     * and the Settings index (SV-02; SA-07, Amendment 35): a
     * card is expired once its expiry month has passed.
     */
    public function isExpired(): bool
    {
        return $this->exp_year < now()->year
            || ($this->exp_year === now()->year && $this->exp_month < now()->month);
    }

    /**
     * An unexpired card whose expiry (the end of its expiry
     * month) lands within 60 days: it warns before it can fail
     * a charge. An expired card never doubles as "soon".
     */
    public function expiresSoon(): bool
    {
        if ($this->isExpired()) {
            return false;
        }

        return \Illuminate\Support\Carbon::create($this->exp_year, $this->exp_month, 1)
            ->endOfMonth()
            ->lessThanOrEqualTo(now()->addDays(60));
    }
}
