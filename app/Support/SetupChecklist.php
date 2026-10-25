<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Membership;
use App\Models\ReferralEvent;
use App\Models\User;

/**
 * The setup checklist's state (spec Section 5.8): one
 * computation shared by the Overview (which renders the card)
 * and the Action Center (whose unfinished-setup row counts the
 * same steps), so the two surfaces can never disagree about
 * how far setup has come. Steps only ever read live rows.
 */
final class SetupChecklist
{
    /**
     * @return array<string, bool>
     */
    public static function for(Business $business, User $user): array
    {
        return [
            'email_verified' => $user->hasVerifiedEmail(),
            'reward_text' => $business->friend_reward_text !== null && trim((string) $business->friend_reward_text) !== '',
            'poster_downloaded' => ReferralEvent::query()
                ->where('business_id', $business->id)
                ->where('event_type', 'poster_downloaded')
                ->exists(),
            // The card-on-file signal that exists in v2 today is
            // the Square customer record; the card capture surface
            // itself ships with billing (Phase 4).
            'card_on_file' => $business->square_customer_id !== null,
            // LV-02 (Train 2; DASH-16): the Google review link
            // lives on Settings -> Reviews.
            'review_link' => $business->google_review_url !== null && trim((string) $business->google_review_url) !== '',
            // LV-02 (DASH-17): the owner has joined their own
            // program with their own number: a live membership
            // whose customer phone is the owner's phone (or the
            // business contact phone, the same number in the
            // fixture world).
            'test_referral' => self::hasSelfMembership($business, $user),
        ];
    }

    private static function hasSelfMembership(Business $business, User $user): bool
    {
        $phones = array_filter([$user->phone_e164, $business->contact_phone_e164]);

        if ($phones === []) {
            return false;
        }

        return Membership::query()
            ->where('business_id', $business->id)
            ->whereNull('removed_at')
            ->whereHas('customer', fn ($query) => $query->whereIn('phone_e164', $phones))
            ->exists();
    }

    /**
     * @param  array<string, bool>  $checklist
     * @return array{0: int, 1: int} done count, total count
     */
    public static function progress(array $checklist): array
    {
        return [collect($checklist)->filter()->count(), count($checklist)];
    }
}
