<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer (spec Section 7.2): one canonical E.164 phone number is
 * one identity, global across businesses. A business sees a customer
 * only through its own membership row (spec Section 7.3 rule 5).
 */
class Customer extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'phone_e164',
        'first_name',
        'last_name',
        'email',
        'canonical_email',
        'first_seen_at',
        'number_linked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'number_linked_at' => 'datetime',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function numberHistory(): HasMany
    {
        return $this->hasMany(CustomerNumberHistory::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
