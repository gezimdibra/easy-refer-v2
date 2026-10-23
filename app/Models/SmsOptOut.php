<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A STOP state for one phone number (spec Section 7.2): platform-
 * wide and permanent. Checked before any send, and checked again
 * inside the message pipeline (spec Section 10.2).
 */
class SmsOptOut extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'phone_e164',
        'opted_out_at',
        'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opted_out_at' => 'datetime',
        ];
    }

    public static function optedOut(string $phoneE164): bool
    {
        return static::query()->where('phone_e164', $phoneE164)->exists();
    }
}
