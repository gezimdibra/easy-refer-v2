<?php

namespace App\Support;

use App\Models\Referral;

/**
 * RT-1 (owner correction, 2026-10-27): the two rewards on a
 * referral, each named by its owner. A referral carries TWO
 * different rewards and the surfaces must never blur them:
 *
 * - The FRIEND's reward: the business's friend reward text,
 *   given in person at the visit the moment staff mark the
 *   visit redeemed. No wait of any kind, ever.
 * - The REFERRER's reward: the Reward row, minted at the
 *   redemption under the OD-3 vesting wait ("On its way",
 *   "Ready on {date}").
 *
 * The bare caption "Reward" beside a vesting date read as the
 * friend waiting weeks for a reward that was in fact handed
 * over at the counter; these lines exist so every referral
 * surface says whose is whose, in the owner's language.
 */
final class ReferralRewardLines
{
    /**
     * The friend's side: what they were promised and when it
     * is (or was) given.
     *
     * @return array{label: string, text: string, note: string}
     */
    public static function friend(Referral $referral): array
    {
        $business = $referral->business;
        $friendName = $referral->referredCustomer?->first_name;

        $label = ($friendName !== null && $friendName !== '' ? $friendName : 'Friend')."'s reward";
        // F-UP-1 (truth sweep 2026-10-29 PM, Known Item 1): a
        // HISTORICAL claim (redeemed / expired / voided) renders
        // its claim-time snapshot, so "Given at the visit on
        // {date}" (and the expired / voided "Not given." notes)
        // sit beside the text AS PROMISED at claim time, never a
        // later edit. promisedFriendRewardText() carries the
        // named LEGACY LIVE-CONFIG FALLBACK for rows whose
        // snapshot is null (rows predating the column). A claim
        // still in flight (pending) is not history: it keeps
        // rendering live config exactly as before.
        $text = $referral->friendRewardIsHistorical()
            ? $referral->promisedFriendRewardText() ?? 'Reward'
            : $business?->rewardText() ?? 'Reward';

        $note = match ($referral->state) {
            Referral::STATE_REDEEMED => $referral->redeemed_at !== null && $business !== null
                ? 'Given at the visit on '.$business->localTime($referral->redeemed_at)->format('M j, Y').'.'
                : 'Given at the visit.',
            Referral::STATE_PENDING => $referral->hold
                ? 'Waiting for review.'
                : 'Given when they visit.',
            Referral::STATE_EXPIRED => 'Not given. The visit window has ended.',
            Referral::STATE_VOIDED => 'Not given.',
            default => '',
        };

        return ['label' => $label, 'text' => $text, 'note' => $note];
    }

    /**
     * The referrer's side: the caption for the Reward row's
     * pill and vesting date, naming the person whose reward
     * is actually on its way.
     */
    public static function referrerLabel(Referral $referral): string
    {
        $referrerName = $referral->referrerMembership?->customer?->first_name;

        return ($referrerName !== null && $referrerName !== '' ? $referrerName : 'Referrer')."'s reward";
    }
}
