<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One refund against a billing record (spec Section 7.2, blueprint
 * Section 1.7). First-class rows, not state edits: the funding
 * record's state summarizes them, this ledger proves them.
 */
class BillingRefund extends Model
{
    public const STATE_PENDING = 'pending';

    public const STATE_SUCCEEDED = 'succeeded';

    public const STATE_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'billing_record_id',
        'square_refund_id',
        'amount_cents',
        'reason',
        'idempotency_key',
        'state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(BillingRecord::class, 'billing_record_id');
    }
}
