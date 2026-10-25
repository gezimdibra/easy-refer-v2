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
     * CUST-09, the owner-add confirmation text (spec Section
     * 5.2): an owner-added number is owner-asserted and
     * unconfirmed until the customer replies YES, which is also
     * what delivers their referral link.
     */
    public static function ownerAddConfirmation(Business $business): string
    {
        return "{$business->name} added you to Easy Refer. Reply YES to confirm and get your referral link, or ignore this text to stay out.";
    }

    /**
     * CUST-11, the share link text: sent when an owner-added
     * customer confirms with a YES reply (spec Section 5.2), the
     * moment their membership becomes fully theirs.
     */
    public static function shareLink(Business $business, string $url): string
    {
        return "{$business->name}: Here is your referral link: {$url}. Share it with friends; when they visit, you earn your reward. Reply STOP to opt out.";
    }

    /**
     * SMS-04, the campaign STOP suffix: a body that does not end
     * with "." gets ". Reply STOP to opt out."; a body that does
     * gets " Reply STOP to opt out.", so the rendered text never
     * doubles the period.
     */
    public static function campaignSuffix(string $ownerBody): string
    {
        return str_ends_with($ownerBody, '.')
            ? ' Reply STOP to opt out.'
            : '. Reply STOP to opt out.';
    }

    /**
     * The campaign body as sent (spec Section 10.3): the system
     * prepends "{BusinessName}: " (threat G-10: the A2P samples
     * are filed with the prefix), then the owner's text, then the
     * SMS-04 suffix. One identical body goes to every recipient.
     */
    public static function campaign(Business $business, string $ownerBody): string
    {
        return "{$business->name}: {$ownerBody}".self::campaignSuffix($ownerBody);
    }

    /**
     * ALERT-03, the first-visit owner alert SMS variant.
     */
    public static function firstVisitAlert(Business $business, string $dashboardUrl): string
    {
        return "{$business->name}: Your first referred customer just visited. See your results: {$dashboardUrl}";
    }
}
