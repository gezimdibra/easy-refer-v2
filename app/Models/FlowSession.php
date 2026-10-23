<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A server-side customer flow session (spec Section 8.4): the
 * record behind the customer's own 30-minute cookie, separate from
 * the owner session. The primary key is the SHA-256 hash of the
 * cookie token; verified state never lives in the cookie itself.
 */
class FlowSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'data',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
