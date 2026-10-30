<?php

namespace App\Services\Campaigns;

use App\Models\Business;
use App\Models\CampaignPromptEvent;
use App\Models\SmsCampaign;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The ONE prompt infrastructure for Idea Train 1 (Amendment
 * 46): a shared event log, per-item suppression POLICIES over
 * it. ID-01's slow-day policy and ID-03's win-back policy
 * differ (a day vs a standing condition) and are implemented
 * per item — never as one rule forced onto both, and a
 * business silenced on one item is never silenced on the
 * other.
 *
 * Counters read only events after the last reset (a relevant
 * send) or re-enable: a send is the state the prompt existed
 * to announce being acted on, so the counts restart clean.
 * Week identity is business-local ISO (key + a serial number
 * for arithmetic).
 */
final class CampaignPrompts
{
    public const ITEM_SLOW_DAY = 'slow_day_boost';

    public const ITEM_WINBACK = 'lapsed_referrer_winback';

    public const ITEM_TOP_REFERRER = 'top_referrer_recognition';

    /**
     * @return array{key: string, serial: int}
     */
    public static function weekContext(Business $business): array
    {
        $local = $business->localTime(now());
        $monday = $local->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();

        return [
            'key' => $local->format('o-\WW'),
            'serial' => (int) intdiv($monday->timestamp, 604800),
        ];
    }

    public static function recordShown(Business $business, string $itemKey): void
    {
        $week = self::weekContext($business);

        $exists = CampaignPromptEvent::query()
            ->where('business_id', $business->id)
            ->where('item_key', $itemKey)
            ->where('week_key', $week['key'])
            ->where('event', CampaignPromptEvent::EVENT_SHOWN)
            ->exists();

        if (! $exists) {
            self::record($business, $itemKey, CampaignPromptEvent::EVENT_SHOWN);
        }
    }

    /**
     * ID-02's shown event is keyed by the STANDINGS MONTH, not
     * the week: the card's identity is the month it honours.
     * The month rides in week_key / week_serial (the log's two
     * generic period columns) so the shared table needs no new
     * column; the top-referrer item key keeps its counters
     * separate from the week-keyed items.
     */
    public static function recordTopReferrerShown(Business $business, string $standingsMonth): void
    {
        $exists = CampaignPromptEvent::query()
            ->where('business_id', $business->id)
            ->where('item_key', self::ITEM_TOP_REFERRER)
            ->where('week_key', 'topref|'.$standingsMonth)
            ->where('event', CampaignPromptEvent::EVENT_SHOWN)
            ->exists();

        if ($exists) {
            return;
        }

        CampaignPromptEvent::create([
            'business_id' => $business->id,
            'item_key' => self::ITEM_TOP_REFERRER,
            'week_key' => 'topref|'.$standingsMonth,
            'week_serial' => (int) str_replace('-', '', $standingsMonth),
            'event' => CampaignPromptEvent::EVENT_SHOWN,
        ]);
    }

    public static function recordActed(Business $business, string $itemKey, ?int $campaignId = null): void
    {
        self::record($business, $itemKey, CampaignPromptEvent::EVENT_ACTED, $campaignId);
    }

    public static function recordDismissed(Business $business, string $itemKey): void
    {
        self::record($business, $itemKey, CampaignPromptEvent::EVENT_DISMISSED);

        // ID-01 shutoff leg: a second dismissal within any
        // rolling 12 weeks turns slow-day reminders OFF until
        // re-enabled (SD-07) or a boost send.
        if ($itemKey === self::ITEM_SLOW_DAY) {
            $week = self::weekContext($business);
            $recent = self::eventsAfterReset($business, $itemKey)
                ->where('event', CampaignPromptEvent::EVENT_DISMISSED)
                ->filter(fn (CampaignPromptEvent $e) => $e->week_serial >= $week['serial'] - 11)
                ->count();

            if ($recent >= 2) {
                $business->forceFill(['slow_day_reminders_off' => true])->save();
            }
        }
    }

    public static function reenable(Business $business, string $itemKey): void
    {
        self::record($business, $itemKey, CampaignPromptEvent::EVENT_REENABLED);

        if ($itemKey === self::ITEM_SLOW_DAY && $business->slow_day_reminders_off) {
            $business->forceFill(['slow_day_reminders_off' => false])->save();
        }
    }

    /**
     * A relevant campaign was sent: the item's dismissal and
     * ignore counts reset to zero (a reset event; counters
     * read after it). A boost send also clears the slow-day
     * reminders-off state.
     */
    public static function noteSent(SmsCampaign $campaign): void
    {
        $business = $campaign->business;

        if ($campaign->audience_segment === SmsCampaign::SEGMENT_LAPSED_REFERRERS) {
            self::record($business, self::ITEM_WINBACK, CampaignPromptEvent::EVENT_RESET, $campaign->id);
        }

        if (is_string($campaign->origin) && str_starts_with($campaign->origin, 'slow_day')) {
            self::record($business, self::ITEM_SLOW_DAY, CampaignPromptEvent::EVENT_RESET, $campaign->id);

            if ($business->slow_day_reminders_off) {
                $business->forceFill(['slow_day_reminders_off' => false])->save();
            }
        }
    }

    /**
     * ID-01's suppression policy (design section 3.4): dismiss
     * once -> silent for the next 4 candidate weeks; ignore
     * three shown prompts in a row -> off entirely (persisted
     * by the caller-visible flag); the off flag itself is the
     * second-dismissal state, set at dismissal time.
     */
    public static function slowDaySilenced(Business $business): bool
    {
        if ($business->slow_day_reminders_off) {
            return true;
        }

        $week = self::weekContext($business);
        $events = self::eventsAfterReset($business, self::ITEM_SLOW_DAY);

        // Ignore streak -> off.
        if (self::ignoredStreak($events, $week['serial']) >= 3) {
            $business->forceFill(['slow_day_reminders_off' => true])->save();

            return true;
        }

        $lastDismissal = $events
            ->where('event', CampaignPromptEvent::EVENT_DISMISSED)
            ->max('week_serial');

        return $lastDismissal !== null
            && $week['serial'] > (int) $lastDismissal
            && $week['serial'] <= (int) $lastDismissal + 4;
    }

    /**
     * ID-03's suppression policy (design section 4.5): dismiss
     * once -> silent for the next 8 candidate weeks; four
     * consecutive shown-but-ignored weeks -> silent for 8
     * weeks from the last shown week. Harder than ID-01's
     * because the win-back condition is standing, not a day.
     */
    public static function winbackSilenced(Business $business): bool
    {
        $week = self::weekContext($business);
        $events = self::eventsAfterReset($business, self::ITEM_WINBACK);

        $lastDismissal = $events
            ->where('event', CampaignPromptEvent::EVENT_DISMISSED)
            ->max('week_serial');

        if ($lastDismissal !== null
            && $week['serial'] > (int) $lastDismissal
            && $week['serial'] <= (int) $lastDismissal + 8) {
            return true;
        }

        // Ignore leg (Condition 4 discharge, 2026-10-28):
        // find a run of >= 4 consecutive shown-but-ignored
        // weeks and silence for 8 weeks from the run's fourth
        // week. The anchor is the RUN, not the trailing
        // streak: a streak read decays the moment a silenced
        // week logs no shown event (the week after the
        // trigger breaks the run on paper), which collapsed
        // the designed 8-week silence to a single week — the
        // Condition 4 pin caught exactly that.
        $shownSerials = $events
            ->where('event', CampaignPromptEvent::EVENT_SHOWN)
            ->pluck('week_serial')
            ->map(fn ($s) => (int) $s)
            ->unique()
            ->all();
        $engagedSerials = $events
            ->whereIn('event', [CampaignPromptEvent::EVENT_ACTED, CampaignPromptEvent::EVENT_DISMISSED])
            ->pluck('week_serial')
            ->map(fn ($s) => (int) $s)
            ->unique()
            ->all();

        $ignoredSerials = array_values(array_diff($shownSerials, $engagedSerials));
        sort($ignoredSerials);

        $runStart = null;
        $previous = null;
        $anchor = null;
        foreach ($ignoredSerials as $serial) {
            if ($previous === null || $serial !== $previous + 1) {
                $runStart = $serial;
                $anchor = null;
            }
            $previous = $serial;

            if ($anchor === null && $serial - $runStart + 1 >= 4) {
                $anchor = $serial;
            }

            if ($anchor !== null
                && $week['serial'] > $anchor
                && $week['serial'] <= $anchor + 8) {
                return true;
            }
        }

        return false;
    }

    /** Whether the owner acted on a slow-day prompt inside the window (ID-03 gate P6). */
    public static function actedOnSlowDayWithin(Business $business, int $days): bool
    {
        return CampaignPromptEvent::query()
            ->where('business_id', $business->id)
            ->where('item_key', self::ITEM_SLOW_DAY)
            ->where('event', CampaignPromptEvent::EVENT_ACTED)
            ->where('created_at', '>', now()->subDays($days))
            ->exists();
    }

    private static function record(Business $business, string $itemKey, string $event, ?int $campaignId = null): void
    {
        $week = self::weekContext($business);

        CampaignPromptEvent::create([
            'business_id' => $business->id,
            'item_key' => $itemKey,
            'week_key' => $week['key'],
            'week_serial' => $week['serial'],
            'event' => $event,
            'campaign_id' => $campaignId,
        ]);
    }

    /**
     * Events after the last reset / re-enable marker (the
     * counters' whole world), oldest first.
     *
     * @return Collection<int, CampaignPromptEvent>
     */
    private static function eventsAfterReset(Business $business, string $itemKey): Collection
    {
        $markerId = CampaignPromptEvent::query()
            ->where('business_id', $business->id)
            ->where('item_key', $itemKey)
            ->whereIn('event', [CampaignPromptEvent::EVENT_RESET, CampaignPromptEvent::EVENT_REENABLED])
            ->max('id');

        return CampaignPromptEvent::query()
            ->where('business_id', $business->id)
            ->where('item_key', $itemKey)
            ->when($markerId !== null, fn ($query) => $query->where('id', '>', $markerId))
            ->orderBy('id')
            ->get();
    }

    /**
     * The trailing streak of shown weeks (strictly before the
     * current week, consecutive serials) in which the owner
     * neither acted nor dismissed. A week the owner engaged
     * with, or a week with no shown event, breaks the streak.
     *
     * @param  Collection<int, CampaignPromptEvent>  $events
     */
    private static function ignoredStreak(Collection $events, int $currentSerial): int
    {
        $shown = $events->where('event', CampaignPromptEvent::EVENT_SHOWN)
            ->pluck('week_serial')->map(fn ($s) => (int) $s)->unique()->all();
        $engaged = $events->whereIn('event', [CampaignPromptEvent::EVENT_ACTED, CampaignPromptEvent::EVENT_DISMISSED])
            ->pluck('week_serial')->map(fn ($s) => (int) $s)->unique()->all();

        $streak = 0;
        for ($serial = $currentSerial - 1; $serial >= 0; $serial--) {
            if (! in_array($serial, $shown, true) || in_array($serial, $engaged, true)) {
                break;
            }
            $streak++;
        }

        return $streak;
    }
}
