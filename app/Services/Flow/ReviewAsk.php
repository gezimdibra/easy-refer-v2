<?php

namespace App\Services\Flow;

use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;

/**
 * ID-05 (Amendment 48): the ONE eligibility service for the
 * Google review ask, governing BOTH receipt surfaces (the
 * friend's redeemed receipt and the referrer's claimed
 * receipt) so the two can never drift apart again.
 *
 * The suppressors are EXACTLY three: (S1) no review link on
 * file, (S2) the 90-day impression cooldown for this
 * (business, customer) — measured from the last recorded
 * IMPRESSION, shared across both surfaces — and (S3) the
 * receipt is not in its completed state (the callers' scope:
 * they only ever ask about a completed receipt). Holds and
 * flow state are NEVER suppressors: a held referral's friend
 * completed their visit, and a paused business's receipt is
 * still the record of a visit that happened.
 *
 * Asked state lives in referral_events: append-only
 * review_ask_shown / review_ask_clicked rows keyed by
 * membership_id. No new table, column, or migration.
 */
final class ReviewAsk
{
    public const EVENT_SHOWN = 'review_ask_shown';

    public const EVENT_CLICKED = 'review_ask_clicked';

    /**
     * Decide for one receipt render. Returns the render flag
     * and whether this render is the receipt's first eligible
     * one (the impression to record). A receipt keeps its own
     * ask: once a source record owns a shown event, its
     * re-views render the ask without recording again and
     * without moving the clock. A receipt whose holder has no
     * membership renders UNRECORDED (E4's defensive case).
     *
     * @return array{render: bool, record: bool}
     */
    public static function evaluate(Business $business, ?Membership $membership, Referral|Reward $source): array
    {
        // S1: no link on file.
        if ($business->google_review_url === null || trim((string) $business->google_review_url) === '') {
            return ['render' => false, 'record' => false];
        }

        if ($membership === null) {
            return ['render' => true, 'record' => false];
        }

        // This receipt already owns its ask.
        if (self::shownForSource($source)) {
            return ['render' => true, 'record' => false];
        }

        // S2: the cooldown, from the membership's last recorded
        // impression on EITHER surface.
        $latest = ReferralEvent::query()
            ->where('membership_id', $membership->id)
            ->where('event_type', self::EVENT_SHOWN)
            ->max('created_at');

        if ($latest !== null) {
            $cooldownDays = max(0, (int) config('referrals.review_ask_cooldown_days', 90));

            if (\Illuminate\Support\Carbon::parse($latest)->addDays($cooldownDays)->isFuture()) {
                return ['render' => false, 'record' => false];
            }
        }

        return ['render' => true, 'record' => true];
    }

    public static function recordShown(Business $business, Membership $membership, Referral|Reward $source, string $surface): void
    {
        ReferralEvent::record(
            self::EVENT_SHOWN,
            'customer',
            $business,
            $source instanceof Referral ? $source : $source->referral,
            $source instanceof Reward ? $source : null,
            $membership,
            ['surface' => $surface],
        );
    }

    /** The first tap per source record counts; later taps still redirect but never double-count. */
    public static function recordClicked(Business $business, ?Membership $membership, Referral|Reward $source, string $surface): void
    {
        $already = ReferralEvent::query()
            ->where('event_type', self::EVENT_CLICKED)
            ->when($source instanceof Referral, fn ($q) => $q->where('referral_id', $source->id)->whereNull('reward_id'))
            ->when($source instanceof Reward, fn ($q) => $q->where('reward_id', $source->id))
            ->exists();

        if ($already) {
            return;
        }

        ReferralEvent::record(
            self::EVENT_CLICKED,
            'customer',
            $business,
            $source instanceof Referral ? $source : $source->referral,
            $source instanceof Reward ? $source : null,
            $membership,
            ['surface' => $surface],
        );
    }

    private static function shownForSource(Referral|Reward $source): bool
    {
        return ReferralEvent::query()
            ->where('event_type', self::EVENT_SHOWN)
            ->when($source instanceof Referral, fn ($q) => $q->where('referral_id', $source->id)->whereNull('reward_id'))
            ->when($source instanceof Reward, fn ($q) => $q->where('reward_id', $source->id))
            ->exists();
    }

    /**
     * The owner stat (REVIEW-03): trailing-30-day impression
     * and tap counts for this business, from the same rows.
     *
     * @return array{shown: int, clicked: int}
     */
    public static function trailingCounts(Business $business): array
    {
        $since = now()->subDays(30);

        return [
            'shown' => ReferralEvent::query()
                ->where('business_id', $business->id)
                ->where('event_type', self::EVENT_SHOWN)
                ->where('created_at', '>=', $since)
                ->count(),
            'clicked' => ReferralEvent::query()
                ->where('business_id', $business->id)
                ->where('event_type', self::EVENT_CLICKED)
                ->where('created_at', '>=', $since)
                ->count(),
        ];
    }
}
