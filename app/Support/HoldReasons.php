<?php

namespace App\Support;

use App\Models\Business;

/**
 * Hold reasons in plain sentences (spec Section 5.6): every rule ID
 * the risk system can write is answerable in the owner's review
 * queue without exposing internal vocabulary.
 */
final class HoldReasons
{
    public static function sentence(string $rule, ?Business $business = null): string
    {
        return match ($rule) {
            'velocity_daily' => 'This referrer reached the daily limit of '.($business?->daily_referral_cap ?? 5).' rewarded referrals.',
            'velocity_monthly' => 'This referrer reached the monthly limit of '.($business?->monthly_referral_cap ?? 50).' rewarded referrals.',
            'device_signal_reuse' => 'The same device was used to verify a different customer at this business.',
            'referrer_concentration' => 'This referrer accounts for more than a quarter of recent redemptions.',
            'redemption_burst' => 'Redemptions spiked well above the usual pace.',
            'near_total_conversion' => 'Almost every new signup is being redeemed, which is unusual.',
            'unattributed_share' => 'Most recent redemptions were not attributed to a staff account.',
            'sim_swap_step_up' => 'This phone number was recently moved to a new SIM card.',
            'number_link_cooling' => 'This claim arrived during the cooling window after the customer linked a new number.',
            default => 'Flagged for review by the automatic risk checks.',
        };
    }

    /**
     * @param  list<string>|null  $rules
     * @return list<string>
     */
    public static function sentences(?array $rules, ?Business $business = null): array
    {
        return array_map(fn (string $rule) => self::sentence($rule, $business), $rules ?? []);
    }
}
