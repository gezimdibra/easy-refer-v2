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
     * The optional steps (Amendment 31, ON-SET-2): steps an
     * owner may finish any time, whose absence is never owed.
     * Every counter in the app — the checklist card, the
     * Action Center setup row, the Overview's standing and
     * setup-complete states — counts REQUIRED steps only, via
     * required()/progress() below, so the surfaces can never
     * disagree about whether setup is done. The optional
     * steps still render as rows (with their done state);
     * they simply never hold a counter open.
     *
     * The card joins the optional set under the activation
     * redesign (owner ruling 2026-10-30; design pass census
     * C-24): a cardless business is live on its first-5 free
     * allowance, so the card is no longer a step setup owes —
     * required drops from 5 to 4 (email_verified, reward_text,
     * poster_downloaded, test_referral). The card's standing
     * ask lives on the Action Center no-card row and the
     * Billing panel, re-derived by allowance state.
     *
     * @var list<string>
     */
    public const OPTIONAL_STEPS = ['review_link', 'card_on_file'];

    /**
     * The steps in their presentation order, which is the
     * dependency order (coherence audit A.3/G1): email,
     * rewards, card — the activation step — then the poster
     * it activates, the test referral that runs through the
     * live flow, and the review link last. Setup is a path,
     * not a pile: worked top-down, each step's outcome is
     * achievable when the owner reaches it.
     *
     * @return array<string, bool>
     */
    public static function for(Business $business, User $user): array
    {
        return [
            'email_verified' => $user->hasVerifiedEmail(),
            'reward_text' => $business->hasFriendRewardText(),
            // Done means the outcome holds (coherence audit
            // G2): a succeeded default payment method exists —
            // read through the gate's own card fact
            // (Business::hasCardOnFile, the one definition) —
            // the verified saved-card record the Action
            // Center's card rows and the billing page already
            // read. Never the Square customer record alone:
            // CardOnFileService stores that id BEFORE the card
            // verifies, so a failed save left it set over a
            // dead flow while this step read done.
            'card_on_file' => $business->hasCardOnFile(),
            'poster_downloaded' => ReferralEvent::query()
                ->where('business_id', $business->id)
                ->where('event_type', 'poster_downloaded')
                ->exists(),
            // LV-02 (DASH-17): the owner has joined their own
            // program with their own number: a live membership
            // whose customer phone is the owner's phone (or the
            // business contact phone, the same number in the
            // fixture world).
            'test_referral' => self::hasSelfMembership($business, $user),
            // LV-02 (Train 2; DASH-16): the Google review link
            // lives on Settings -> Reviews.
            'review_link' => $business->google_review_url !== null && trim((string) $business->google_review_url) !== '',
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
     * The required subset of a checklist (ON-SET-2): every
     * step except the optional ones, in the original order.
     * This is the one definition of "required" every counter
     * consumer shares. (Onboarding::states() maps exactly
     * this same five-key set into flow order, so the
     * dashboard resume banner's "of 5 required steps done"
     * count agrees with progress() by construction.)
     *
     * (Onboarding::states() maps the flow's steps from this
     * same computation; the flow's own optional step — the
     * card — mirrors this list through its checklist key, so
     * the dashboard resume banner's "of 4 required steps
     * done" count agrees with progress() by construction.)
     *
     * @param  array<string, bool>  $checklist
     * @return array<string, bool>
     */
    public static function required(array $checklist): array
    {
        return array_diff_key($checklist, array_flip(self::OPTIONAL_STEPS));
    }
    // RS2-2 note: Onboarding::states() maps these same five
    // required keys into flow order and adds a sixth,
    // onboarding-only Location state computed from the business
    // row; the checklist itself gains no Location item, so every
    // "of 5 required steps" count fed by progress() stays true.

    /**
     * @param  array<string, bool>  $checklist
     * @return array{0: int, 1: int} required done count, required total count
     */
    public static function progress(array $checklist): array
    {
        $required = self::required($checklist);

        return [collect($required)->filter()->count(), count($required)];
    }

    /** Setup is complete when every required step is done. */
    public static function requiredComplete(array $checklist): bool
    {
        [$done, $total] = self::progress($checklist);

        return $done >= $total;
    }
}
