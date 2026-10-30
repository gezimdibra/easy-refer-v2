<?php

namespace App\Support;

/**
 * A human device summary for a session row (R2 SET-SECURITY,
 * design section 9): "Chrome on Windows", "Safari on iPhone".
 * A presentation transform of the user-agent string the
 * sessions table already stores; the full string stays
 * available to the view for the row's title attribute, for
 * support. Shared by any future session list.
 */
final class DeviceSummary
{
    public static function of(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };

        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $os === null ? $browser : $browser.' on '.$os;
    }
}
