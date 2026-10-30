<?php

namespace App\Services\Campaigns;

use App\Models\Referral;
use App\Models\Reward;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsOptOut;
use Carbon\CarbonInterface;

/**
 * The campaign closed-loop read model (RK-02; copy Amendment
 * 23, CAMP-22/23). Everything derives from stored rows at read
 * time: delivery states and per-recipient amounts from the
 * campaign's recipient rows, opt-outs from the recipient rows'
 * send-time re-check plus platform STOP records stamped at or
 * after the send, and the visit windows from a windowed join
 * over referrals and rewards per recipient membership.
 *
 * The language discipline is the feature: a recipient counts as
 * "visited" when, after the text was sent, a friend they
 * referred visited (a redeemed referral) or they used a reward
 * (a claimed reward), inside the window. That is a visit AFTER
 * the text, never a visit caused by it; the page says so.
 */
final class CampaignResults
{
    /**
     * @return array{
     *     delivered: int,
     *     failed: int,
     *     opted_out: int,
     *     cost_cents: int,
     *     visited_7: int,
     *     visited_14: int,
     *     failed_membership_ids: list<int>,
     *     resend_ids: list<int>
     * }
     */
    public static function forCampaign(SmsCampaign $campaign): array
    {
        $recipients = $campaign->recipients()->get();
        $sentAt = $campaign->sent_at ?? $campaign->created_at;

        $delivered = $recipients
            ->where('delivery_state', SmsCampaignRecipient::STATE_DELIVERED)
            ->count();
        $failedRows = $recipients->whereIn('delivery_state', [
            SmsCampaignRecipient::STATE_FAILED,
            SmsCampaignRecipient::STATE_UNDELIVERED,
        ]);

        // Opted out: the recipients the send's own re-check
        // found already opted out (skipped rows), plus any
        // recipient number that sent STOP at or after this
        // campaign's send. The two sets cannot overlap: a
        // skipped recipient's opt-out predates the send.
        $skippedOptOuts = $recipients
            ->where('delivery_state', SmsCampaignRecipient::STATE_SKIPPED)
            ->where('skip_reason', SmsCampaignRecipient::SKIP_OPTED_OUT)
            ->count();
        $phones = $recipients->pluck('phone_e164')->unique()->all();
        $postSendOptOuts = $phones === [] ? 0 : SmsOptOut::query()
            ->whereIn('phone_e164', $phones)
            ->where('opted_out_at', '>=', $sentAt)
            ->count();

        // Cost: what the attempted sends metered, summed from
        // the recipient rows themselves (rows that carry a
        // provider message id were attempted).
        $attempted = $recipients->whereNotNull('sms_message_id');
        $costCents = (int) $attempted->sum('amount_cents');
        $attemptedMembershipIds = $attempted->pluck('membership_id')->unique()->values()->all();

        $failedIds = $failedRows->pluck('membership_id')->unique()->values()->all();

        // The resend scope, re-derived NOW through the one
        // eligibility object: the source's failed recipients who
        // are still eligible. The stored list only narrows; it
        // never grants eligibility.
        // ID-03: a segment campaign's resend scope re-derives
        // through its own segment (with this campaign exempt
        // from the win-back cooldown — its failed recipients
        // are the resend's whole point); a whole-list campaign
        // re-derives whole-list, exactly as before.
        $eligibleNow = CampaignAudience::forBusiness($campaign->business, $campaign->audience_segment, $campaign->id)['memberships']
            ->pluck('id')
            ->all();
        $resendIds = array_values(array_intersect($failedIds, $eligibleNow));

        return [
            'delivered' => $delivered,
            'failed' => $failedRows->count(),
            'opted_out' => $skippedOptOuts + $postSendOptOuts,
            'cost_cents' => $costCents,
            'visited_7' => self::visitedWithin($attemptedMembershipIds, $sentAt, 7),
            'visited_14' => self::visitedWithin($attemptedMembershipIds, $sentAt, 14),
            'failed_membership_ids' => $failedIds,
            'resend_ids' => $resendIds,
        ];
    }

    /**
     * How many of the given memberships visited within the
     * window after the text: a redeemed referral they referred,
     * or a reward they claimed, in (sent_at, sent_at + days].
     * Each membership counts once however it visited.
     *
     * @param  list<int>  $membershipIds
     */
    private static function visitedWithin(array $membershipIds, CarbonInterface $sentAt, int $days): int
    {
        if ($membershipIds === []) {
            return 0;
        }

        $until = $sentAt->copy()->addDays($days);

        $viaReferral = Referral::query()
            ->whereIn('referrer_membership_id', $membershipIds)
            ->where('state', Referral::STATE_REDEEMED)
            ->whereBetween('redeemed_at', [$sentAt, $until])
            ->pluck('referrer_membership_id');

        $viaReward = Reward::query()
            ->whereIn('membership_id', $membershipIds)
            ->where('state', Reward::STATE_CLAIMED)
            ->whereBetween('claimed_at', [$sentAt, $until])
            ->pluck('membership_id');

        return $viaReferral->merge($viaReward)->unique()->count();
    }
}
