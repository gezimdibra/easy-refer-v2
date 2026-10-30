<?php

namespace App\Support;

use App\Models\Reward;

/**
 * The owner-facing reward status vocabulary, shared by the
 * Customers and Rewards surfaces. One name per state everywhere
 * (design P9): the pill words are the design Section 2.6
 * glossary words the Rewards filter tabs also use ("On its way",
 * "Ready to claim"). Inventory DASH-06's older pill words
 * ("Pending vesting", "Available") are superseded by that
 * glossary; the reconciliation is recorded in the inventory's
 * Section 17.7. A hold is a flag orthogonal to state and wins
 * the display, mirroring ReferralStatus.
 */
final class RewardStatus
{
    /**
     * @return array{label: string, pill: string}
     */
    public static function present(Reward $reward): array
    {
        if ($reward->hold) {
            return ['label' => 'Held', 'pill' => 'pill-warning'];
        }

        return match ($reward->state) {
            Reward::STATE_PENDING_VESTING => ['label' => 'On its way', 'pill' => 'pill-info'],
            Reward::STATE_AVAILABLE => ['label' => 'Ready to claim', 'pill' => 'pill-success'],
            Reward::STATE_CLAIMED => ['label' => 'Claimed', 'pill' => 'pill-neutral'],
            Reward::STATE_DENIED => ['label' => 'Denied', 'pill' => 'pill-error'],
            Reward::STATE_EXPIRED => ['label' => 'Expired', 'pill' => 'pill-neutral'],
            default => ['label' => ucfirst((string) $reward->state), 'pill' => 'pill-neutral'],
        };
    }

    public static function label(Reward $reward): string
    {
        return self::present($reward)['label'];
    }

    /**
     * FE-VEST-01 (state visibility): when a reward renders as
     * "On its way", the date it lands, from the stored vesting
     * timestamp in the business's local time — the same fact
     * the customer is told, so the owner can answer "when do I
     * get it?" without calendar math. Null everywhere else:
     * ready and claimed rewards gain nothing, and a held
     * reward (whose pill reads "Held", the hold wins the
     * display) gains nothing either.
     */
    public static function readyOn(Reward $reward): ?string
    {
        if ($reward->hold
            || $reward->state !== Reward::STATE_PENDING_VESTING) {
            return null;
        }

        if ($reward->available_at === null) {
            // F-5: a vesting reward with no stored timestamp
            // still carries its window (the configured hold),
            // never a bare windowless "On its way".
            return self::vestingWindow($reward);
        }

        return 'Ready on '.$reward->business->localTime($reward->available_at)->format('M j, Y');
    }

    /**
     * F-5 (effectiveness audit, 2026-10-29): the vesting window
     * as a plain remaining-time magnitude, for the surfaces that
     * cannot show the exact date — above all the bare claim link
     * opened outside the referrer's own session (G-17 keeps the
     * date itself session-only, but a bare "On its way" with no
     * window at all is a state claim with no checkable value).
     * Derived from the reward's actual vesting timestamp: the
     * days remaining until available_at, rounded up so the
     * sentence stays true at every moment it renders. With no
     * stored timestamp it falls back to the business's
     * configured vesting days, clamped to the OD-3 platform
     * bounds exactly as RedemptionService mints them. Null
     * everywhere the reward is not plainly vesting: a held
     * reward's wait is a review, not a window, and an available
     * or terminal reward never carries vesting language at all.
     */
    public static function vestingWindow(Reward $reward): ?string
    {
        if ($reward->hold || $reward->state !== Reward::STATE_PENDING_VESTING) {
            return null;
        }

        if ($reward->available_at !== null) {
            $days = max(1, (int) ceil(now()->diffInSeconds($reward->available_at) / 86400));
        } else {
            $configured = (int) ($reward->business?->reward_vesting_days ?? 0);
            $days = $configured >= (int) config('referrals.vesting_days_min')
                && $configured <= (int) config('referrals.vesting_days_max')
                ? $configured
                : (int) config('referrals.vesting_days_default');
        }

        return 'Ready in about '.$days.' '.($days === 1 ? 'day' : 'days').'.';
    }

    /**
     * M-4 [M4-REWL-08]: the use-by line for a plainly ready
     * reward, from its stamped expires_at in the business's
     * local time. Returns the FULL line ("Good until {date}")
     * so templates print it verbatim, the readyOn convention.
     * Null everywhere else, exactly the readyOn discipline: a
     * held reward renders no date at all (its clock is paused,
     * so any date computed across the pause would be a
     * state-blind date), a vesting reward's date is 12 months
     * past a date the owner already sees, and terminal rows gain
     * nothing. A ready row with no stamped date (the F-5 legacy
     * population) renders nothing rather than inventing one.
     */
    public static function goodUntil(Reward $reward): ?string
    {
        if ($reward->hold
            || $reward->state !== Reward::STATE_AVAILABLE
            || $reward->expires_at === null) {
            return null;
        }

        return 'Good until '.$reward->business->localTime($reward->expires_at)->format('M j, Y');
    }

    /**
     * The notification cell vocabulary (REWL-03) for the Rewards
     * list's Text column.
     */
    public static function notificationLabel(Reward $reward): string
    {
        return match ($reward->notification_state) {
            'sent' => 'Sent',
            'delivered' => 'Delivered',
            'failed' => 'Failed',
            'suppressed' => 'Stopped (opted out)',
            default => 'Not sent yet',
        };
    }
}
