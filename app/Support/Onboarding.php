<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;

/**
 * The onboarding front door's flow state (P4, onboarding design
 * pack Sections 4 and 5). Step done-ness is NEVER stored: five
 * steps are the SetupChecklist's own computation, remapped into
 * the flow's presentation order, so onboarding, the checklist
 * card, and the Action Center can never disagree about what is
 * done (the pack's assumption A4 and the audit's R5).
 *
 * The flow's order is its own (benchmark ruling O-1): Verify
 * sits between Card and Poster, because verification activates
 * nothing and is the one step that sends the owner out of the
 * flow into their inbox. The checklist's presentation order
 * does not move.
 *
 * RS2-2 adds a sixth flow step, Location, between Card and
 * Verify (the value-train design pass, Part 3 Variant A: the
 * signup address moved out of the register wizard into
 * onboarding, before Poster). Location is an ONBOARDING-ONLY
 * step per the design pass's default ruling: it does NOT join
 * SetupChecklist (the checklist keeps its five required steps,
 * and its "of 5 required steps" counts stay true), and its
 * done-ness is computed here from the business row itself:
 * an address is set exactly when street is non-blank. The
 * flow's resume machinery (firstOpenStep) covers it like any
 * other step, so a checkpoint account abandoned before its
 * address re-enters at Location.
 *
 * The only stored fact is businesses.onboarding_completed_at
 * (OB-U1's migration): that the owner passed the finish screen.
 */
final class Onboarding
{
    /** Flow steps in presentation order (slugs). */
    public const STEPS = ['rewards', 'card', 'location', 'verify', 'poster', 'test'];

    /** Step slug -> the SetupChecklist key that decides it. */
    private const CHECKLIST_KEYS = [
        'rewards' => 'reward_text',
        'card' => 'card_on_file',
        'verify' => 'email_verified',
        'poster' => 'poster_downloaded',
        'test' => 'test_referral',
    ];

    /**
     * The flow's optional steps (activation redesign, owner
     * ruling 2026-10-30; design pass census C-24): the card
     * step stays IN the flow's order — walkable, addable, one
     * tap for the owner who wants it — but it leaves the
     * required set, mirroring SetupChecklist::OPTIONAL_STEPS
     * through CHECKLIST_KEYS (card -> card_on_file). An undone
     * card can therefore never be the step the resume
     * machinery strands a cardless owner on: firstOpenStep()
     * skips it, and doneCount() counts required steps only,
     * so every "of N required steps done" surface reads 4.
     *
     * @var list<string>
     */
    private const OPTIONAL_STEPS = ['card'];

    /** How many flow steps are required (5 working steps - 1 optional). */
    public static function requiredTotal(): int
    {
        return count(self::STEPS) - count(self::OPTIONAL_STEPS);
    }

    /** Step slug -> its short name (the O-2 labeled stepper). */
    public const LABELS = [
        'rewards' => 'Rewards',
        'card' => 'Card',
        'location' => 'Location',
        'verify' => 'Verify',
        'poster' => 'Poster',
        'test' => 'Try it',
    ];

    /**
     * Step states in flow order. The five checklist steps come
     * from the checklist computation (a caller that already
     * computed this request's checklist, like the dashboard,
     * passes it in, so no query runs twice); Location is
     * computed from the business's address columns.
     *
     * @param  array<string, bool>|null  $checklist
     * @return array<string, bool>
     */
    public static function states(Business $business, User $user, ?array $checklist = null): array
    {
        $checklist ??= SetupChecklist::for($business, $user);

        $states = [];
        foreach (self::STEPS as $step) {
            $states[$step] = $step === 'location'
                ? trim((string) $business->street) !== ''
                : (bool) ($checklist[self::CHECKLIST_KEYS[$step]] ?? false);
        }

        return $states;
    }

    /**
     * Done REQUIRED steps (the optional card never counts
     * toward, or against, the required total).
     *
     * @param  array<string, bool>  $states
     */
    public static function doneCount(array $states): int
    {
        return collect($states)
            ->except(self::OPTIONAL_STEPS)
            ->filter()
            ->count();
    }

    /**
     * The first open REQUIRED step in flow order, or null when
     * every required step is done (the finish screen is next).
     * An open optional step (the card) never blocks: it stays
     * reachable by its own route, in flow order, but resume
     * never lands an owner on it as the blocking step.
     *
     * @param  array<string, bool>  $states
     */
    public static function firstOpenStep(array $states): ?string
    {
        // RS2-2 merge ruling, FINAL FORM (coordinator,
        // 2026-10-30; corrected once on ActionCenterCoherence
        // S6): the resume target is the first open step in FLOW
        // ORDER while any REQUIRED step is still open — the
        // button says "Pick up where you left off", and flow
        // order is that sentence's literal truth; the optional
        // Card step is a target only in that world (unreached
        // or unskipped, one tap either way). The moment every
        // required step is done the flow reads COMPLETE (null)
        // even with the card undone: the owner is live, the
        // missing card belongs to the Action Center and
        // Settings, and a banner reading "5 of 5 required steps
        // done" over "partway through setup" is exactly the
        // contradiction the flow-state class exists to kill.
        // The card never gates the count or activation
        // (doneCount/requiredTotal keep their own predicates).
        // Pins: SignupPersistenceTest boundaries, OnboardingTest
        // banner, ActivationAllowanceTest P8, ActionCenter S6.
        $requiredOpen = false;
        foreach (self::STEPS as $step) {
            if (in_array($step, self::OPTIONAL_STEPS, true)) {
                continue;
            }
            if (! ($states[$step] ?? false)) {
                $requiredOpen = true;
                break;
            }
        }

        if (! $requiredOpen) {
            return null;
        }

        foreach (self::STEPS as $step) {
            if (! ($states[$step] ?? false)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The step after the given one in flow order (the Skip and
     * Continue target), or 'finish' past the last working step.
     */
    public static function nextStep(string $step): string
    {
        $index = array_search($step, self::STEPS, true);

        if ($index === false || $index === count(self::STEPS) - 1) {
            return 'finish';
        }

        return self::STEPS[$index + 1];
    }

    /** 1-based position in the working-step count ("Step N of 6"). */
    public static function position(string $step): int
    {
        $index = array_search($step, self::STEPS, true);

        return $index === false ? 1 : $index + 1;
    }

    /**
     * Onboarding is incomplete while any working step is open
     * and the finish screen has not been passed. This is the
     * predicate the resume surfaces (dashboard banner, checklist
     * re-pointing, the Action Center setup row) key on.
     */
    public static function isIncomplete(Business $business, User $user, ?array $checklist = null): bool
    {
        if ($business->onboarding_completed_at !== null) {
            return false;
        }

        return self::firstOpenStep(self::states($business, $user, $checklist)) !== null;
    }

    /** The finish screen has been passed (either door). */
    public static function hasCompleted(Business $business): bool
    {
        return $business->onboarding_completed_at !== null;
    }
}
