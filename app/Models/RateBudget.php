<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A database rate budget counter (spec Section 10.4): keyed by scope
 * and subject hash, never by session, so budgets survive session
 * churn and identity resets.
 */
class RateBudget extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'count',
        'window_started_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'window_started_at' => 'datetime',
        ];
    }
}
