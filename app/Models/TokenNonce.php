<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A staff redemption nonce (spec Section 6.1 item 4): bound to one
 * token subject and one page render, single-use, 30-minute life.
 * Rendering again invalidates every earlier unused nonce for the
 * subject, so at most one live nonce exists per token.
 */
class TokenNonce extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subject_type',
        'subject_id',
        'nonce_hash',
        'expires_at',
        'consumed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
