<?php

namespace App\Support;

/**
 * R4-U1 (redesign/r4-rewards-print, Surfaces 1/2/5): the one state
 * language for the customer flow and the staff screens. Two scales
 * of the same kit: the customer pill (flow/claim, flow/redeem) and
 * the staff band (staff/*), grown to counter distance. Families are
 * shared, so a customer and a staff member describing the same
 * screen use the same words. Labels are copy Amendment 26
 * (R4-STATE-01/02, PROPOSED, coordinator ratification pending);
 * state is never carried by color alone: every entry pairs its
 * fill with its label.
 */
final class FlowState
{
    /**
     * Customer pill set.
     *
     * @return array{label: string, pill: string}
     */
    public static function pill(string $state): array
    {
        return match ($state) {
            'ready' => ['label' => 'Ready', 'pill' => 'pill-success'],
            'on_its_way' => ['label' => 'On its way', 'pill' => 'pill-warning'],
            'in_review' => ['label' => 'In review', 'pill' => 'pill-warning'],
            'expired' => ['label' => 'Expired', 'pill' => 'pill-neutral'],
            'not_approved' => ['label' => 'Not approved', 'pill' => 'pill-error'],
            default => ['label' => ucfirst(str_replace('_', ' ', $state)), 'pill' => 'pill-neutral'],
        };
    }

    /**
     * Staff band set. The band answers "can I press?" before a word
     * of body text is read; an optional detail rides after the
     * label (a date, in the pack's mappings).
     *
     * @return array{label: string, band: string}
     */
    public static function band(string $state): array
    {
        return match ($state) {
            'ready_to_redeem' => ['label' => 'Ready to redeem', 'band' => 'state-band-success'],
            'ready_to_claim' => ['label' => 'Ready to claim', 'band' => 'state-band-success'],
            'already_redeemed' => ['label' => 'Already redeemed', 'band' => 'state-band-neutral'],
            'already_claimed' => ['label' => 'Already claimed', 'band' => 'state-band-neutral'],
            'owner_review' => ['label' => 'Owner review needed', 'band' => 'state-band-warning'],
            'expired' => ['label' => 'Expired', 'band' => 'state-band-neutral'],
            'not_yet' => ['label' => 'Not yet', 'band' => 'state-band-neutral'],
            default => ['label' => ucfirst(str_replace('_', ' ', $state)), 'band' => 'state-band-neutral'],
        };
    }
}
