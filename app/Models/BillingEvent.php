<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One verified visit, recorded at redemption (spec Section 3 Step
 * 10). The billing system's only input; immutable after insert.
 */
class BillingEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'referral_id',
        'billing_record_id',
        'amount_cents',
        'coverage',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }
}
