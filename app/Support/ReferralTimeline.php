<?php

namespace App\Support;

/**
 * The referral timeline vocabulary (REFL-06): referral_events
 * types rendered as owner-readable labels. The map covers the
 * event types the referral lifecycle writes; anything else falls
 * back to the generic Update label rather than leaking a machine
 * name onto the page.
 */
final class ReferralTimeline
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'referral_created' => 'Started',
        'scan' => 'Redeem link opened',
        'redemption' => 'Visit completed',
        'redemption_rejected' => 'Redemption rejected',
        'expired' => 'Expired',
        'referral_voided' => 'Voided',
        'flag_raised' => 'Held for review',
        'review_release' => 'Released from review',
        'review_deny' => 'Denied in review',
        'redeem_token_regenerated' => 'New redeem link sent',
    ];

    public static function label(string $eventType): string
    {
        return self::LABELS[$eventType] ?? 'Update';
    }
}
