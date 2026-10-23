<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded phone-number link on a customer record (spec Section
 * 7.2, EC-16): when a customer verifies with a new number, the old
 * and new canonical numbers are linked by the verification event so
 * earned rewards and limits follow the person.
 */
class CustomerNumberHistory extends Model
{
    protected $table = 'customer_number_history';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'phone_e164',
        'linked_at',
        'link_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
