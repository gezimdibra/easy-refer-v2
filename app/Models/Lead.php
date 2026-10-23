<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A captured lead (spec Sections 2.1, 2.7): the footer newsletter
 * (with a confirmation token, never a dead form) and the starter kit
 * request form.
 */
class Lead extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'kind',
        'email',
        'source',
        'token_hash',
        'confirmed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }
}
