<?php

namespace App\Services\Campaigns;

use App\Models\Business;
use App\Models\Membership;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsOptOut;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The campaign eligibility query (spec Sections 10.3, 5.2): ONE
 * object used by the estimate endpoint, the confirm screen, and
 * the send, so the three numbers can never disagree about the
 * rule. A membership is eligible iff it is not removed, its
 * consent is on, its consent provenance is customer-granted
 * (customer_checkbox or customer_reply; owner_asserted is NEVER
 * eligible on its own, threat G-04), and its number is not on
 * the platform opt-out list (EC-22: any business's STOP stops
 * everyone's campaigns).
 */
final class CampaignAudience
{
    /** Consent sources a customer granted themselves. */
    public const CUSTOMER_SOURCES = ['customer_checkbox', 'customer_reply'];

    /**
     * The segment parameter (ID-03, Amendment 46) intersects
     * INSIDE this audience — never beside it: a segment member
     * is evaluated by the same precedence (opt-out -> consent
     * -> provenance -> F1 recency -> win-back cooldown) and
     * counted under exactly one reason; a non-member is a
     * scope fact, never an exclusion. The win-back cooldown
     * exclusion appends after F1's reasons (it is the only
     * other purely temporal reason, and F1's lifts first).
     * $excludeWinbackCampaignId is the resend own-send
     * exception: touches from that campaign do not bind its
     * own failed recipients' resend.
     *
     * @return array{
     *     memberships: Collection<int, Membership>,
     *     exclusions: array{owner_asserted_unconfirmed: int, opted_out: int, no_consent: int, recently_texted_spacing: int, recently_texted_cap: int, winback_cooldown: int},
     *     recency_earliest_return: ?Carbon,
     *     recency_return_by_id: array<int, Carbon>,
     *     segment_size: ?int,
     *     winback_earliest_return: ?Carbon
     * }
     */
    public static function forBusiness(Business $business, ?string $segment = null, ?int $excludeWinbackCampaignId = null): array
    {
        $memberships = Membership::query()
            ->where('business_id', $business->id)
            ->whereNull('removed_at')
            ->with('customer')
            ->orderBy('id')
            ->get();

        $optedOutPhones = SmsOptOut::query()
            ->whereIn('phone_e164', $memberships->map(fn (Membership $m) => $m->customer->phone_e164))
            ->pluck('phone_e164')
            ->flip();

        $eligible = collect();
        $exclusions = [
            'owner_asserted_unconfirmed' => 0,
            'opted_out' => 0,
            'no_consent' => 0,
            'recently_texted_spacing' => 0,
            'recently_texted_cap' => 0,
            'winback_cooldown' => 0,
        ];
        $earliestReturn = null;
        $recencyReturns = [];
        $recencySkippedIds = ['spacing' => [], 'cap' => []];
        $winbackSkippedIds = [];

        // The segment layer (ID-03): membership ids + the
        // phone-keyed win-back touch history, read only when a
        // segment is in play, so whole-list reads (and the
        // dashboard's budget) are untouched.
        $segmentIds = null;
        $segmentSize = null;
        $winbackTouchesByPhone = collect();
        $winbackEarliestReturn = null;
        $cooldownDays = max(0, (int) config('campaigns.winback_cooldown_days', 90));

        if ($segment === SmsCampaign::SEGMENT_LAPSED_REFERRERS) {
            $segmentIds = array_flip(LapsedReferrerSegment::ids($business));
            $segmentSize = count($segmentIds);

            $winbackTouchesByPhone = SmsCampaignRecipient::query()
                ->whereHas('campaign', fn ($query) => $query
                    ->where('business_id', $business->id)
                    ->where('audience_segment', SmsCampaign::SEGMENT_LAPSED_REFERRERS)
                    ->whereIn('status', [SmsCampaign::STATUS_SENT, SmsCampaign::STATUS_SENDING])
                    ->when($excludeWinbackCampaignId !== null, fn ($q) => $q->where('id', '!=', $excludeWinbackCampaignId)))
                ->whereIn('delivery_state', [
                    SmsCampaignRecipient::STATE_QUEUED,
                    SmsCampaignRecipient::STATE_SENT,
                    SmsCampaignRecipient::STATE_DELIVERED,
                    // The cooldown's own ledger (design 4.6):
                    // a failed win-back attempt still counts —
                    // the silence, not the delivery outcome, is
                    // what the cooldown protects. (F1's ledger
                    // above keeps its own rule: failed never
                    // counts there.)
                    SmsCampaignRecipient::STATE_FAILED,
                    SmsCampaignRecipient::STATE_UNDELIVERED,
                ])
                ->where('created_at', '>', now()->subDays($cooldownDays))
                ->orderBy('created_at')
                ->get(['phone_e164', 'created_at'])
                ->groupBy('phone_e164');
        } elseif ($segment !== null) {
            // An unknown segment names nobody: the sendable set
            // is empty rather than silently the whole list.
            $segmentIds = [];
            $segmentSize = 0;
        }

        // F1 (Amendment 45): the per-recipient frequency cap's
        // history read. One touch = one recipient row handed to
        // the provider (queued/sent/delivered) on a sent or
        // sending campaign of THIS business, keyed by the
        // snapshotted phone — never by membership, so removing
        // and re-adding a customer cannot launder history, and
        // never across businesses, so one business's sends can
        // never move another's counts (the non-leak rule).
        $spacingDays = max(0, (int) config('campaigns.recency_spacing_days', 7));
        $windowDays = max(0, (int) config('campaigns.recency_window_days', 30));
        $maxTouches = max(1, (int) config('campaigns.recency_max_touches', 4));
        $now = now();
        $windowStart = $now->copy()->subDays($windowDays);
        $spacingStart = $now->copy()->subDays($spacingDays);

        $phones = $memberships->map(fn (Membership $m) => $m->customer->phone_e164)->unique()->all();
        $touchesByPhone = $phones === [] ? collect() : SmsCampaignRecipient::query()
            ->whereHas('campaign', fn ($query) => $query
                ->where('business_id', $business->id)
                ->whereIn('status', [SmsCampaign::STATUS_SENT, SmsCampaign::STATUS_SENDING]))
            ->whereIn('phone_e164', $phones)
            ->whereIn('delivery_state', [
                SmsCampaignRecipient::STATE_QUEUED,
                SmsCampaignRecipient::STATE_SENT,
                SmsCampaignRecipient::STATE_DELIVERED,
            ])
            ->where('created_at', '>', $windowStart)
            ->orderBy('created_at')
            ->get(['phone_e164', 'created_at'])
            ->groupBy('phone_e164');

        foreach ($memberships as $membership) {
            $phone = $membership->customer->phone_e164;

            // The segment scope: a non-member is not counted
            // under ANY exclusion in a segment read — they were
            // never this campaign's audience, and the scope
            // line (WB-09) is what accounts for them. Members
            // inside the segment still pass every standing
            // filter below in its precedence; the intersection
            // can only narrow, never widen.
            if ($segmentIds !== null && ! isset($segmentIds[$membership->id])) {
                continue;
            }

            // One reason per member, in precedence order: the
            // opt-out is absolute, then consent simply off,
            // then unconfirmed provenance. A member with consent
            // off AND owner-asserted provenance therefore counts
            // as no_consent (pinned by CampaignAudienceTest,
            // FB-02: the design names no precedence for this
            // overlap, so the code's order is the record).
            if ($optedOutPhones->has($phone)) {
                $exclusions['opted_out']++;

                continue;
            }

            if (! $membership->sms_consent) {
                $exclusions['no_consent']++;

                continue;
            }

            if (! in_array($membership->consent_source, self::CUSTOMER_SOURCES, true)) {
                $exclusions['owner_asserted_unconfirmed']++;

                continue;
            }

            // Recency last (F1 precedence): spacing before
            // volume, so a member inside 7 days is never also
            // counted at the cap. Boundaries are exclusive: a
            // touch exactly 7 (or 30) days old no longer binds.
            $touches = $touchesByPhone->get($phone, collect())
                ->sortBy(fn ($touch) => $touch->created_at)
                ->values();
            $lastTouch = $touches->last();
            $spacingBlocked = $lastTouch !== null && $lastTouch->created_at->greaterThan($spacingStart);
            $capBlocked = $touches->count() >= $maxTouches;

            if ($spacingBlocked || $capBlocked) {
                // The exclusion bucket keeps the precedence
                // (spacing first); the return instant does NOT
                // follow the bucket. Two-key Condition 3
                // (2026-10-28): the member's true re-eligibility
                // is the LATER of both limbs' clearances —
                // (a) spacing: the last touch + spacingDays;
                // (b) volume: the (count − max + 1)th-oldest
                // touch + windowDays, i.e. the touch whose
                // aging-out brings the in-window count back to
                // max − 1. Computing either limb alone hands a
                // member bound by both (or holding more touches
                // than the cap) a date on which the other limb
                // still skips them.
                if ($spacingBlocked) {
                    $exclusions['recently_texted_spacing']++;
                    $recencySkippedIds['spacing'][] = $membership->id;
                } else {
                    $exclusions['recently_texted_cap']++;
                    $recencySkippedIds['cap'][] = $membership->id;
                }

                $return = $lastTouch->created_at->copy()->addDays($spacingDays);

                if ($capBlocked) {
                    $volumeReturn = $touches[$touches->count() - $maxTouches]->created_at->copy()->addDays($windowDays);
                    $return = $volumeReturn->greaterThan($return) ? $volumeReturn : $return;
                }

                $earliestReturn = $earliestReturn === null || $return->lessThan($earliestReturn) ? $return : $earliestReturn;
                // The member's OWN return instant, kept beside
                // the business-wide minimum: a one-person
                // surface (the recognition confirm) must name
                // its own customer's date, never another
                // customer's earlier one (two-key Condition 2).
                $recencyReturns[$membership->id] = $return;

                continue;
            }

            // The win-back cooldown, after F1 (precedence
            // section 4.3): phone-keyed, so a removed and
            // re-added membership inherits the phone's clock.
            if ($segmentIds !== null) {
                $winbackTouches = $winbackTouchesByPhone->get($phone, collect());
                $lastWinback = $winbackTouches->last();

                if ($lastWinback !== null) {
                    $exclusions['winback_cooldown']++;
                    $winbackSkippedIds[] = $membership->id;
                    $return = $lastWinback->created_at->copy()->addDays($cooldownDays);
                    $winbackEarliestReturn = $winbackEarliestReturn === null || $return->lessThan($winbackEarliestReturn)
                        ? $return : $winbackEarliestReturn;

                    continue;
                }
            }

            $eligible->push($membership);
        }

        return [
            'memberships' => $eligible,
            'exclusions' => $exclusions,
            'recency_earliest_return' => $earliestReturn,
            'recency_return_by_id' => $recencyReturns,
            'recency_skipped_ids' => $recencySkippedIds,
            'segment_size' => $segmentSize,
            'winback_earliest_return' => $winbackEarliestReturn,
            'winback_skipped_ids' => $winbackSkippedIds,
        ];
    }

    /**
     * ID-03 job-time backstop: whether this phone has a
     * win-back touch from this business in a DIFFERENT
     * campaign (the job's own campaign and its exempt source
     * campaign never count) inside the cooldown window.
     */
    public static function winbackTouchedByOtherCampaign(Business $business, string $phone, int $excludeCampaignId, ?int $exemptCampaignId = null): bool
    {
        $cooldownDays = max(0, (int) config('campaigns.winback_cooldown_days', 90));

        return SmsCampaignRecipient::query()
            ->whereHas('campaign', fn ($query) => $query
                ->where('business_id', $business->id)
                ->where('audience_segment', SmsCampaign::SEGMENT_LAPSED_REFERRERS)
                ->whereIn('status', [SmsCampaign::STATUS_SENT, SmsCampaign::STATUS_SENDING])
                ->where('id', '!=', $excludeCampaignId)
                ->when($exemptCampaignId !== null, fn ($q) => $q->where('id', '!=', $exemptCampaignId)))
            ->where('phone_e164', $phone)
            ->whereIn('delivery_state', [
                SmsCampaignRecipient::STATE_QUEUED,
                SmsCampaignRecipient::STATE_SENT,
                SmsCampaignRecipient::STATE_DELIVERED,
                SmsCampaignRecipient::STATE_FAILED,
                SmsCampaignRecipient::STATE_UNDELIVERED,
            ])
            ->where('created_at', '>', now()->subDays($cooldownDays))
            ->exists();
    }

    /**
     * F1 job-time backstop read (Amendment 45): whether this
     * phone has a campaign touch from this business in a
     * DIFFERENT campaign inside either limb's window, as of
     * now. The own-campaign exclusion is load-bearing: a
     * campaign's own rows must never make its later recipients
     * "recent" to their own send.
     */
    public static function recentlyTouchedByOtherCampaign(Business $business, string $phone, int $excludeCampaignId): bool
    {
        $spacingDays = max(0, (int) config('campaigns.recency_spacing_days', 7));
        $windowDays = max(0, (int) config('campaigns.recency_window_days', 30));
        $maxTouches = max(1, (int) config('campaigns.recency_max_touches', 4));
        $now = now();

        $touches = SmsCampaignRecipient::query()
            ->whereHas('campaign', fn ($query) => $query
                ->where('business_id', $business->id)
                ->whereIn('status', [SmsCampaign::STATUS_SENT, SmsCampaign::STATUS_SENDING]))
            ->where('campaign_id', '!=', $excludeCampaignId)
            ->where('phone_e164', $phone)
            ->whereIn('delivery_state', [
                SmsCampaignRecipient::STATE_QUEUED,
                SmsCampaignRecipient::STATE_SENT,
                SmsCampaignRecipient::STATE_DELIVERED,
            ])
            ->where('created_at', '>', $now->copy()->subDays($windowDays))
            ->orderBy('created_at')
            ->pluck('created_at');

        if ($touches->isEmpty()) {
            return false;
        }

        if ($touches->last()->greaterThan($now->copy()->subDays($spacingDays))) {
            return true;
        }

        return $touches->count() >= $maxTouches;
    }
}
