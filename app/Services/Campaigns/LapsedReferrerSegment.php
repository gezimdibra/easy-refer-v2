<?php

namespace App\Services\Campaigns;

use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use Illuminate\Support\Carbon;

/**
 * The lapsed-referrer segment read (ID-03, Amendment 46;
 * design section 1). A membership is in the segment iff:
 *  (a) it is a PROVEN referrer: at least one QUALIFYING
 *      referral — state redeemed, its billing coverage not
 *      fraud_denied and not held, its reward (where one
 *      exists) not denied and not held. Denied/held-only
 *      referrers never qualify; denial taint is per-row and
 *      permanent, a later clean row qualifies on its own.
 *  (b) MAX(referrals.created_at) over ALL referrals naming
 *      it as referrer (every state — creation is the factual
 *      last time a friend entered the flow through their
 *      link) is >= winback_lapse_days ago (boundary inclusive:
 *      exactly 45x24h ago is lapsed).
 *  (c) it has NO live pending referral (pending and not yet
 *      expired — a visit in flight is a contradiction).
 *  (d) its share token is active (the template's "Your
 *      referral link still works" is guaranteed by this leg).
 * Removed memberships are out (the audience excludes them in
 * any case; the segment size must not count them either).
 *
 * There is NO share event in the data: referral creation is
 * the only referrer-activity clock, and memberships'
 * referral_count is never a segment input (it counts
 * creations including later-denied rows).
 */
final class LapsedReferrerSegment
{
    /**
     * @return list<int> membership ids in the segment
     */
    public static function ids(Business $business): array
    {
        $lapseDays = max(1, (int) config('campaigns.winback_lapse_days', 45));
        $now = now();
        $lapsedBefore = $now->copy()->subDays($lapseDays);

        // One aggregate read over the business's referrals,
        // joined to the two taint sources. Grouping is by the
        // referrer membership; the flags are per-row sums so a
        // mixed history qualifies on its clean row.
        $rows = Referral::query()
            ->where('referrals.business_id', $business->id)
            ->leftJoin('rewards', 'rewards.referral_id', '=', 'referrals.id')
            ->leftJoin('billing_events', 'billing_events.referral_id', '=', 'referrals.id')
            ->groupBy('referrals.referrer_membership_id')
            ->selectRaw(
                "referrals.referrer_membership_id as membership_id, "
                ."MAX(referrals.created_at) as last_created_at, "
                ."SUM(CASE WHEN referrals.state = '".Referral::STATE_REDEEMED."' "
                ."AND (billing_events.coverage IS NULL OR billing_events.coverage NOT IN ('fraud_denied', 'held')) "
                ."AND (rewards.id IS NULL OR (rewards.state != 'denied' AND COALESCE(rewards.hold, 0) = 0)) "
                ."THEN 1 ELSE 0 END) as qualifying, "
                ."SUM(CASE WHEN referrals.state = '".Referral::STATE_PENDING."' "
                ."AND (referrals.expires_at IS NULL OR referrals.expires_at > ?) "
                ."THEN 1 ELSE 0 END) as live_pending",
                [$now->toDateTimeString()],
            )
            ->get();

        $candidateIds = [];
        foreach ($rows as $row) {
            if ((int) $row->qualifying < 1 || (int) $row->live_pending > 0) {
                continue;
            }

            $last = $row->last_created_at === null ? null : Carbon::parse((string) $row->last_created_at);
            if ($last === null || $last->greaterThan($lapsedBefore)) {
                continue;
            }

            $candidateIds[] = (int) $row->membership_id;
        }

        if ($candidateIds === []) {
            return [];
        }

        return Membership::query()
            ->where('business_id', $business->id)
            ->whereIn('id', $candidateIds)
            ->whereNull('removed_at')
            ->where('share_token_state', 'active')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
