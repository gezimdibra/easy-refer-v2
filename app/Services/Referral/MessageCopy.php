<?php

namespace App\Services\Referral;

use App\Models\Business;

/**
 * The transactional SMS templates (inventory Section 12), rendered
 * in exactly one place so the template evidence (SMS-24) covers
 * every send: expected content present, every placeholder filled.
 */
final class MessageCopy
{
    /**
     * SMS-02, the referrer reward-ready text (the v1 typo
     * "succesful" is fixed per the inventory).
     */
    public static function rewardReady(Business $business, string $rewardText, string $claimUrl): string
    {
        return "{$business->name}: Congratulations for the successful referral! Your reward '{$rewardText}' is ready to be claimed: {$claimUrl}";
    }

    /**
     * SMS-03, the optional friend link copy, sent only with the
     * friend's consent.
     */
    public static function friendLink(Business $business, string $referrerFirstName, string $url): string
    {
        return "{$business->name}: {$referrerFirstName} invited you. Claim your reward: {$url}. Reply STOP to opt out.";
    }

    /**
     * ALERT-03, the first-visit owner alert SMS variant.
     */
    public static function firstVisitAlert(Business $business, string $dashboardUrl): string
    {
        return "{$business->name}: Your first referred customer just visited. See your results: {$dashboardUrl}";
    }
}
