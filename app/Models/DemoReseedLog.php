<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One nightly demo reseed attempt (Amendment 63, Section
 * 11.3). The ledger is read by DemoService::resting(): two
 * consecutive failures take the demo entry points down.
 */
class DemoReseedLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'succeeded',
        'note',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
