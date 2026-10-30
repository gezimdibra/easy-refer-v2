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
     *
     * M-4 [M4-SMS-02]: when the reward carries its stamped
     * use-by date, the body gains the disclosure clause " Good
     * until {date}." as part of the BASE body, before any ID-07
     * reshare appendage (the appendage composes after the whole
     * base body, exactly as it composed after the claim link).
     * The date is the reward's expires_at in business-local
     * "M j, Y", formatted by the caller, which holds the reward.
     * A reward with no stamped date (the F-5 legacy population)
     * sends the pre-M-4 body byte-identically: no date is ever
     * invented for it.
     */
    public static function rewardReady(Business $business, string $rewardText, string $claimUrl, ?string $goodUntilDate = null): string
    {
        $body = "{$business->name}: Congratulations for the successful referral! Your reward '{$rewardText}' is ready to be claimed: {$claimUrl}";

        if ($goodUntilDate !== null && $goodUntilDate !== '') {
            $body .= " Good until {$goodUntilDate}.";
        }

        return $body;
    }

    /**
     * M-4 [M4-SMS-EXP-01], the expiry warning text: the reward's
     * one reminder, sent inside its last 30 days on the
     * transactional basis (see SendRewardExpiryWarningSms).
     * {date} is the reward's expires_at in business-local
     * "M j, Y", formatted by the caller.
     */
    public static function rewardExpiryWarning(Business $business, string $rewardText, string $date, string $claimUrl): string
    {
        return "{$business->name}: Your reward '{$rewardText}' is still waiting for you. It runs out on {$date}. Show this code at the counter to claim it: {$claimUrl}";
    }

    /**
     * ID-07 [RR-SMS-02A/B] (Amendment 51): the reshare appendage
     * that may ride SMS-02: the recipient's OWN share link and
     * their own next reward, inside the reward consent's scope
     * ("text messages about my rewards"); it stays transactional
     * (design pass Section 3 ruling + binding conditions). The
     * came-as-a-friend cohort's form opens with [F2R-03] (ID-08
     * inheritance, discharged by ID-07 which owns this surface).
     * No reward amount, no "earn" verb (a capped or held next
     * mint must never be promised in a text), no offer, no
     * urgency, GSM-7 only. Whether it rides at all is the job's
     * RR-GATE / RR-CAP / RR-SEG decision, never the composer's.
     */
    public static function rewardReadyReshareAppendage(string $shareUrl, bool $cameAsFriend): string
    {
        return $cameAsFriend
            ? " You came in through a friend yourself. Share your link: {$shareUrl}"
            : " Share your link with a friend: {$shareUrl}";
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
     * OB-05 (Amendment 45): the share-link text while the
     * business's flow is inactive. The link is still delivered
     * (the customer asked for it), but the earning sentence is
     * dropped: while the flow is off, a visit cannot earn.
     */
    public static function shareLinkFlowInactive(Business $business, string $url): string
    {
        return "{$business->name}: Here is your referral link: {$url}. Reply STOP to opt out.";
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
     * A body that already carries the opt-out line (U6, P-OWN-1:
     * the owner typed "Reply STOP to opt out." themselves, in any
     * casing) gets no second copy appended.
     */
    public static function campaign(Business $business, string $ownerBody): string
    {
        if (stripos($ownerBody, 'STOP to opt out') !== false) {
            return "{$business->name}: {$ownerBody}";
        }

        return "{$business->name}: {$ownerBody}".self::campaignSuffix($ownerBody);
    }

    /**
     * TR-07 (ID-02, Amendment 48): the recognition render. The
     * recipient's own share link rides as a render-time
     * appendage between the owner body and the SMS-04 suffix;
     * it is never stored in the draft body, so no stored
     * artefact keeps a live token in plaintext (the send job
     * redacts the evidence copy, F-09 pattern).
     */
    public static function recognition(Business $business, string $ownerBody, \App\Models\Membership $recipient): string
    {
        $url = route('refer.friend', ['token' => $recipient->plainShareToken()]);
        $withLink = "{$ownerBody} Your link: {$url}";

        if (stripos($ownerBody, 'STOP to opt out') !== false) {
            return "{$business->name}: {$withLink}";
        }

        return "{$business->name}: {$withLink} Reply STOP to opt out.";
    }

    /**
     * ALERT-03, the first-visit owner alert SMS variant.
     */
    public static function firstVisitAlert(Business $business, string $dashboardUrl): string
    {
        return "{$business->name}: Your first referred customer just visited. See your results: {$dashboardUrl}";
    }
}
