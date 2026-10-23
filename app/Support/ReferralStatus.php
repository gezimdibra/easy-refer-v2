<?php

namespace App\Support;

use App\Models\Referral;

/**
 * The owner-facing referral status vocabulary (inventory
 * DASH-06), shared by the Overview, Customers, and Referrals
 * surfaces so a state reads the same everywhere. A hold is a
 * flag orthogonal to state (spec Section 5.3) and wins the
 * display: a held referral says Held, with the reason beside it.
 */
final class ReferralStatus
{
    /**
     * @return array{label: string, pill: string}
     */
    public static function present(Referral $referral): array
    {
        if ($referral->hold) {
            return ['label' => 'Held', 'pill' => 'pill-warning'];
        }

        return match ($referral->state) {
            Referral::STATE_PENDING => ['label' => 'Pending redemption', 'pill' => 'pill-info'],
            Referral::STATE_REDEEMED => ['label' => 'Redeemed', 'pill' => 'pill-success'],
            Referral::STATE_EXPIRED => ['label' => 'Expired', 'pill' => 'pill-neutral'],
            Referral::STATE_VOIDED => ['label' => 'Voided', 'pill' => 'pill-error'],
            default => ['label' => ucfirst((string) $referral->state), 'pill' => 'pill-neutral'],
        };
    }

    public static function label(Referral $referral): string
    {
        return self::present($referral)['label'];
    }

    /**
     * The Visit column's presentation (REFL-03): the visit state
     * only. A hold is carried by the row's "Held for review"
     * line, never by replacing the visit state, so a held
     * referral still reads Waiting here (UI verifier F-06).
     *
     * @return array{label: string, pill: string}
     */
    public static function visitPresent(Referral $referral): array
    {
        return match ($referral->state) {
            Referral::STATE_PENDING => ['label' => 'Waiting', 'pill' => 'pill-info'],
            Referral::STATE_EXPIRED => ['label' => 'Expired', 'pill' => 'pill-neutral'],
            Referral::STATE_VOIDED => ['label' => 'Voided', 'pill' => 'pill-error'],
            default => ['label' => 'Waiting', 'pill' => 'pill-info'],
        };
    }
}
