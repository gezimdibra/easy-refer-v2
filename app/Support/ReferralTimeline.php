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
        // N-5 (Amendment 45): the referral-scoped types the
        // lifecycle actually records that used to fall back to
        // the bare "Update" label, named from what each event
        // is. (Enrollment/verification/poster types carry no
        // referral and never render on this timeline.)
        'reward_minted' => 'Referrer reward earned',
        'reward_claimed' => 'Reward claimed',
        'clawback' => 'Reward clawed back',
        'risk_signal' => 'Risk signal recorded',
        'redemption_blocked_missing_reward_text' => 'Redemption blocked: no reward was set',
    ];

    public static function label(string $eventType): string
    {
        return self::LABELS[$eventType] ?? 'Update';
    }

    /**
     * RD-4: the timeline node's tone by event class. Completion
     * is success, a hold or flag is warning, everything else
     * stays neutral. The text label always renders beside the
     * node, so tone is never the only signal (WCAG).
     */
    public static function nodeTone(string $eventType): string
    {
        return match ($eventType) {
            'redemption' => 'success',
            'flag_raised', 'review_deny', 'redemption_rejected' => 'warning',
            default => 'neutral',
        };
    }
}
