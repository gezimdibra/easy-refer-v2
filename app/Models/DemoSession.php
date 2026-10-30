<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A server-side demo session (G6 demo Phase 1, Section 11): the
 * record behind the demo cookie, mirroring the FlowSession
 * shape. The primary key is the SHA-256 hash of the cookie
 * token. This store is the ONLY place the prospect's typed
 * phone number exists (Section 5.2): it dies with the record
 * at the fixed 15-minute expiry, at completion exit, at
 * restart, or in the reseed sweep.
 */
class DemoSession extends Model
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
