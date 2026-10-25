<?php

namespace App\Support;

/**
 * Plain-language labels for audit actions (inventory ADMC-11).
 * The audit log stores dotted machine keys; no surface may render
 * one raw (UI verifier F-01). Every action the application writes
 * has a label here; an action without one renders as spaced words,
 * still never the raw key.
 */
final class AuditActionLabel
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'admin.login' => 'Admin signed in',
        'admin.login_failed' => 'Admin sign-in failed',
        'admin.logout' => 'Admin signed out',
        'admin.console_view' => 'Console page viewed',
        'admin.business_suspended' => 'Business paused',
        'admin.business_reinstated' => 'Business reinstated',
        'admin.two_factor_enabled' => 'Admin two-factor set up',
        'admin.two_factor_enrollment_started' => 'Admin two-factor setup started',
        'admin.two_factor_enrollment_failed' => 'Admin two-factor setup failed',
        'admin.two_factor_challenge_passed' => 'Admin two-factor check passed',
        'admin.two_factor_challenge_failed' => 'Admin two-factor check failed',
        'business.average_ticket_updated' => 'Average ticket updated',
        'business.checklist_dismissed' => 'Setup checklist dismissed',
        'business.checklist_reset' => 'Setup checklist reset',
        'business.closed' => 'Business closed',
        'business.notifications_updated' => 'Notification settings updated',
        'business.profile_updated' => 'Business profile updated',
        'business.review_url_updated' => 'Review link updated',
        'business.reward_texts_updated' => 'Reward texts updated',
        'business.velocity_limits_updated' => 'Referral limits updated',
        'customer.added' => 'Customer added',
        'customer.details_updated' => 'Customer details updated',
        'customer.removed_from_store' => 'Customer removed',
        'owner.account_unlocked' => 'Account unlocked',
        'owner.all_sessions_revoked' => 'All sessions signed out',
        'owner.email_change_requested' => 'Email change requested',
        'owner.email_verification_sent' => 'Verification email sent',
        'owner.email_verified' => 'Email verified',
        'owner.login' => 'Signed in',
        'owner.login_failed' => 'Sign-in failed',
        'owner.login_lockout' => 'Sign-in locked',
        'owner.logout' => 'Signed out',
        'owner.other_sessions_revoked' => 'Other sessions signed out',
        'owner.password_changed' => 'Password changed',
        'owner.password_reset' => 'Password reset',
        'owner.password_reset_requested' => 'Password reset requested',
        'owner.registered' => 'Account created',
        'owner.remember_token_theft' => 'Remember-me token theft detected',
        'owner.step_up_failed' => 'Step-up check failed',
        'owner.step_up_verified' => 'Step-up verified',
        'owner.two_factor_challenge_failed' => 'Two-factor check failed',
        'owner.two_factor_challenge_passed' => 'Two-factor check passed',
        'owner.two_factor_disabled' => 'Two-factor turned off',
        'owner.two_factor_enabled' => 'Two-factor set up',
        'owner.two_factor_enrollment_started' => 'Two-factor setup started',
        'qr.poster_downloaded' => 'Poster downloaded',
        'qr.regenerated' => 'QR code regenerated',
        'referral.redeem_link_regenerated' => 'Redeem link regenerated',
        'review.referral_denied' => 'Referral denied',
        'review.referral_released' => 'Referral released',
        'review.reward_denied' => 'Reward denied',
        'review.reward_released' => 'Reward released',
        'reward.notification_resent' => 'Reward text resent',
    ];

    public static function for(string $action): string
    {
        if (isset(self::LABELS[$action])) {
            return self::LABELS[$action];
        }

        $words = str_replace(['.', '_'], ' ', $action);

        return ucfirst($words);
    }
}
