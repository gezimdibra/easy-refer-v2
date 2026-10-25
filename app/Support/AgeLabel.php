<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * An age in plain words (copy Amendment 22, REV-02): "under an
 * hour", "5 hours", "3 days", "2 weeks", "1 month". One helper
 * serves every surface that states how long something has
 * been waiting (review holds, the Action Center's stale-hold
 * row), so the format cannot drift between them. Buckets are
 * whole units, floored: an age never rounds up into a unit it
 * has not reached.
 */
final class AgeLabel
{
    public static function of(CarbonInterface $from, ?CarbonInterface $now = null): string
    {
        $now ??= now();
        $seconds = max(0, $from->diffInSeconds($now, true));

        if ($seconds < 3600) {
            return 'under an hour';
        }

        $hours = intdiv($seconds, 3600);
        if ($hours < 48) {
            return $hours === 1 ? '1 hour' : $hours.' hours';
        }

        $days = intdiv($seconds, 86400);
        if ($days < 14) {
            return $days === 1 ? '1 day' : $days.' days';
        }

        $weeks = intdiv($days, 7);
        if ($weeks < 9) {
            return $weeks === 1 ? '1 week' : $weeks.' weeks';
        }

        $months = max(1, intdiv($days, 30));

        return $months === 1 ? '1 month' : $months.' months';
    }
}
