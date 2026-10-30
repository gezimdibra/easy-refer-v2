<?php

namespace App\Services\Campaigns;

use App\Models\Business;
use App\Models\Referral;
use App\Models\SlowDayAnalyzerLog;
use Illuminate\Support\Carbon;

/**
 * The slow-day floor computation (ID-01 design section 1.3).
 *
 * DORMANT IN THIS BUILD (owner ruling 2026-10-28: slow day is
 * owner-declared only; there is no data baseline pre-launch).
 * analyze() computes the floor honestly; analyzeAndRecord()
 * logs it. NOTHING in this build renders, prompts from, or
 * otherwise surfaces the result — it is recorded so the
 * post-launch data candidate's population is known from data.
 * The floor: trailing 8 complete business-local weeks of
 * redeemed referrals; gates F1 history span, F2 volume >= 56,
 * F3 margin (slowest <= 60% of the average weekday, strictly
 * lowest by >= 2), F4 split-half stability (in each half, at
 * most 2 weekdays sit at or below the candidate's total), F5
 * closed-day guard (candidate has >= 1 visit and is not zero
 * on >= 6 of its 8 occurrences; failing candidates fall
 * through to the next-lowest).
 */
final class SlowDayAnalyzer
{
    /**
     * @return array{candidate_weekday: ?int, passes: bool, gates: array<string, bool>, totals: array<int, int>, window_start: string, window_end: string}
     */
    public static function analyze(Business $business): array
    {
        $weeks = max(1, (int) config('campaigns.slow_day_floor_weeks', 8));
        $minVisits = max(1, (int) config('campaigns.slow_day_floor_min_visits', 56));
        $marginPercent = max(1, (int) config('campaigns.slow_day_floor_margin_percent', 60));

        // Trailing complete business-local weeks: the window
        // ends at the start of the current local week (Monday).
        $localNow = $business->localTime(now());
        $windowEndLocal = $localNow->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $windowStartLocal = $windowEndLocal->copy()->subWeeks($weeks);
        $windowStartUtc = $windowStartLocal->copy()->setTimezone('UTC');
        $windowEndUtc = $windowEndLocal->copy()->setTimezone('UTC');

        $redeemed = Referral::query()
            ->where('business_id', $business->id)
            ->where('state', Referral::STATE_REDEEMED)
            ->whereNotNull('redeemed_at')
            ->pluck('redeemed_at');

        $firstRedeemed = $redeemed->min();

        $totals = array_fill(0, 7, 0);
        $occurrencesWithVisit = array_fill(0, 7, 0);
        $halfTotals = [array_fill(0, 7, 0), array_fill(0, 7, 0)];
        $inWindow = 0;

        foreach ($redeemed as $redeemedAt) {
            if ($redeemedAt->lt($windowStartUtc) || $redeemedAt->gte($windowEndUtc)) {
                continue;
            }
            $inWindow++;
            $local = $business->localTime($redeemedAt);
            $weekday = (int) $local->dayOfWeek;
            $totals[$weekday]++;
            // Which occurrence (week index) and which half.
            $daysIn = (int) $windowStartLocal->copy()->startOfDay()->diffInDays($local->copy()->startOfDay());
            $weekIndex = intdiv($daysIn, 7);
            $halfTotals[$weekIndex < intdiv($weeks, 2) ? 0 : 1][$weekday]++;
        }

        // Occurrences (week indexes) with at least one visit,
        // per weekday, via a seen-set.
        $seen = [];
        foreach ($redeemed as $redeemedAt) {
            if ($redeemedAt->lt($windowStartUtc) || $redeemedAt->gte($windowEndUtc)) {
                continue;
            }
            $local = $business->localTime($redeemedAt);
            $weekday = (int) $local->dayOfWeek;
            $daysIn = (int) $windowStartLocal->copy()->startOfDay()->diffInDays($local->copy()->startOfDay());
            $seen[$weekday][intdiv($daysIn, 7)] = true;
        }
        foreach ($seen as $weekday => $set) {
            $occurrencesWithVisit[$weekday] = count($set);
        }

        $gates = [
            'history_span' => $firstRedeemed !== null && Carbon::parse((string) $firstRedeemed)->lte(now()->subDays($weeks * 7)),
            'volume' => $inWindow >= $minVisits,
        ];

        $average = $inWindow / 7.0;
        $order = collect(range(0, 6))->sortBy(fn (int $d) => $totals[$d])->values()->all();

        $candidate = null;
        $candidateGates = ['margin' => false, 'split_half' => false, 'closed_day_guard' => false];

        foreach ($order as $weekday) {
            $total = $totals[$weekday];
            // F5 closed-day guard first: a near-always-zero
            // weekday is most likely a closed day.
            $zeroOccurrences = $weeks - (int) $occurrencesWithVisit[$weekday];
            // F5 as designed for 8 weeks: zero on >= 6 of 8
            // occurrences fails; generalized as >= weeks - 2.
            $guard = $total >= 1 && $zeroOccurrences < $weeks - 2;
            if (! $guard) {
                continue;
            }
            // F3 margin: <= margin% of average, strictly lowest
            // by >= 2 (no other weekday within 1 of it, unless
            // that weekday already failed the guard... the
            // strictly-lowest test is against every other day).
            $others = collect($totals)->except($weekday);
            $margin = $total <= ($average * $marginPercent / 100.0)
                && $others->min() >= $total + 2;
            if (! $margin) {
                continue;
            }
            // F4 split-half: in each half, at most 2 weekdays
            // sit at or below the candidate's half total.
            $stable = true;
            foreach ($halfTotals as $half) {
                $atOrBelow = collect($half)->filter(fn (int $t) => $t <= $half[$weekday])->count();
                if ($atOrBelow > 2) {
                    $stable = false;
                    break;
                }
            }
            if (! $stable) {
                continue;
            }

            $candidate = $weekday;
            $candidateGates = ['margin' => true, 'split_half' => true, 'closed_day_guard' => true];
            break;
        }

        $gates = array_merge($gates, $candidateGates);
        $passes = $gates['history_span'] && $gates['volume'] && $candidate !== null;

        return [
            'candidate_weekday' => $passes ? $candidate : null,
            'passes' => $passes,
            'gates' => $gates,
            'totals' => $totals,
            'window_start' => $windowStartLocal->toDateString(),
            'window_end' => $windowEndLocal->toDateString(),
        ];
    }

    /**
     * Run the computation and record it, at most once per
     * business per ISO week. The result is returned to NO
     * view: callers discard it.
     */
    public static function analyzeAndRecord(Business $business): ?SlowDayAnalyzerLog
    {
        $weekStart = now()->copy()->startOfWeek(Carbon::MONDAY);

        $existing = SlowDayAnalyzerLog::query()
            ->where('business_id', $business->id)
            ->where('created_at', '>=', $weekStart)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $result = self::analyze($business);

        return SlowDayAnalyzerLog::create([
            'business_id' => $business->id,
            'candidate_weekday' => $result['candidate_weekday'],
            'gates' => $result['gates'],
            'totals' => $result['totals'],
        ]);
    }
}
