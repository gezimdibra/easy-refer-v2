<?php

namespace App\Services\Security;

use App\Models\RateBudget;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Database rate budgets (spec Section 10.4). Counters are keyed by
 * scope and subject hash, never by session, so they survive session
 * churn and identity resets. Windows are fixed and epoch-aligned;
 * the increment runs inside a transaction with a row lock, so
 * concurrent hits serialize and the final count is exact (CONC-08:
 * a budget evaluated at its limit admits exactly that many).
 */
class RateBudgetService
{
    public static function keyFor(string $scope, string $subjectHash): string
    {
        return $scope.':'.$subjectHash;
    }

    /**
     * The current count in the live window, without incrementing.
     */
    public function count(string $key, int $windowSeconds = 3600): int
    {
        $row = RateBudget::query()->where('key', $key)->first();

        if ($row === null || $row->window_started_at->lessThan($this->windowStart($windowSeconds))) {
            return 0;
        }

        return (int) $row->count;
    }

    /**
     * Record one hit and return the new count in the live window.
     */
    public function hit(string $key, int $windowSeconds = 3600): int
    {
        $windowStart = $this->windowStart($windowSeconds);

        return DB::transaction(function () use ($key, $windowStart) {
            $row = RateBudget::query()->where('key', $key)->lockForUpdate()->first();

            if ($row === null) {
                try {
                    RateBudget::create([
                        'key' => $key,
                        'count' => 1,
                        'window_started_at' => $windowStart,
                    ]);

                    return 1;
                } catch (QueryException) {
                    // Lost an insert race: the winner's row exists
                    // now; fall through and increment it.
                    $row = RateBudget::query()->where('key', $key)->lockForUpdate()->firstOrFail();
                }
            }

            if ($row->window_started_at->lessThan($windowStart)) {
                $row->count = 1;
                $row->window_started_at = $windowStart;
            } else {
                $row->count = (int) $row->count + 1;
            }

            $row->save();

            return (int) $row->count;
        });
    }

    private function windowStart(int $windowSeconds): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $epoch = (int) $now->timestamp;
        $aligned = $epoch - ($epoch % $windowSeconds);

        return CarbonImmutable::createFromTimestamp($aligned);
    }
}
