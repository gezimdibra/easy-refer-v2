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
