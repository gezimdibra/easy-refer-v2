<?php

namespace App\Support;

use App\Models\Business;

/**
 * The one source for the failed-payment words (SV-03, Train 2;
 * copy Amendment 22 BILL-PD-01, amended by Amendment 35
 * SA-01). The billing banner, the dashboard gate, and the
 * Action Center row all state a failed payment in exactly
 * these words, so the sentences live here once and every
 * surface reads them from here.
 */
final class BillingCopy
{
    public static function pastDueLine(): string
    {
        return 'A payment failed. Please update your card to keep billing current.';
    }

    public static function pausedLine(): string
    {
        return 'Your QR code and customer links are paused until you update your card.';
    }

    /**
     * The remedy sentence for the past-due PAUSED state (two-key
     * re-review Finding 2, 2026-10-30): saving a card alone
     * never unpauses a past_due business — CardOnFileService
     * flips only none/setup_incomplete to active — so the true
     * remedy names both halves: update the card AND complete a
     * successful charge (the SquareWebhookHandler transition
     * back to active). One source, so the finish screen, the
     * QR-inactive notice, and the billing no-card panel cannot
     * drift into the false "add a card and your code turns on".
     */
    public static function pausedRemedyLine(): string
    {
        return 'Update your card and complete a successful charge to turn it back on.';
    }

    /**
     * The history clause that stands beside the paused remedy
     * on the three paused branches (billing no-card panel,
     * QR-inactive notice, onboarding finish), truth sweep
     * 2026-10-30 item 2f. The clause lives here, beside
     * pausedRemedyLine(), so the three surfaces cannot drift.
     *
     * It is a CLAUSE, not a sentence: each surface keeps its
     * own grammar around it (the billing panel ends it with a
     * period, the QR notice the same, the finish screen
     * continues ", so your code is paused."). Its truth depends
     * on the episode EVIDENCE, not just the paused state: the
     * grace period is only known to have ended when the
     * episode is dated (dunningGraceEndsAt() !== null). In the
     * audit-row-absent fail-closed state no grace period was
     * ever dated, so the clause asserts only the failure —
     * the finish screen's composition then reads exactly
     * "A payment failed, so your code is paused." and no
     * surface invents an ending it cannot date.
     */
    public static function pausedCauseClause(Business $business): string
    {
        if ($business->dunningGraceEndsAt() === null) {
            return 'A payment failed';
        }

        return 'A payment failed and the grace period has ended';
    }

    public static function offLine(): string
    {
        return 'Your QR code and customer links are off.';
    }

    /**
     * The time line that stands beside the failed-payment
     * sentence (SA-01, Amendment 35; FLOW-STATE TRUTH train,
     * 2026-10-30): while the grace window runs it names the
     * real grace-end date, in the business's own timezone, the
     * same date the dunning emails print; once the window has
     * closed and the flow has paused, it states the paused
     * truth instead of claiming the code still works.
     *
     * There is NO dateless form of the keeps-working claim.
     * The retired fallback rendered "keep working during the
     * grace period" whenever an episode could not be dated,
     * and undated episodes never paused — the SA-01 lie kept
     * alive in a fallback branch. The derivation dates the
     * episode ONLY from its billing.past_due audit row
     * (Business::dunningGraceEndsAt — the failed-record
     * fallback was removed by the two-key re-review Finding 1,
     * 2026-10-30: an unbound record pick can fabricate a date
     * in either direction), and an episode with no audit row
     * fails closed: dunningPausedAt() is true, flowIsActive()
     * is false, and this line states the paused truth, so the
     * surface and the gate can never disagree.
     */
    /**
     * The allowance-paused line (activation redesign, census
     * C-22): a past-due business that removes its only card
     * mid-grace with the allowance spent is paused BEFORE the
     * grace date, for the allowance cause — every grace-date
     * sentence is false for it from that moment.
     */
    public static function allowancePausedLine(): string
    {
        return 'Your QR code and customer links are paused until you add a card.';
    }

    public static function statusLine(Business $business): string
    {
        // State-truth walk (2026-10-28): a business that is
        // not active (closed or disabled) has its flow off for
        // a reason a card update cannot fix, so neither the
        // keeps-working line nor the paused line is true in
        // that state; the only true claim is that it is off.
        if (! $business->isActive()) {
            return self::offLine();
        }

        // Activation redesign (census C-22; design pass
        // Section 4.6 step 3): the allowance branch stands
        // AHEAD of the grace-date line. A past-due business
        // whose block reason is the allowance (card removed
        // mid-grace, allowance spent) is paused now, whatever
        // the episode clock says; naming its grace date would
        // promise working days it does not have.
        if ($business->flowBlockReason() === 'allowance_exhausted') {
            return self::allowancePausedLine();
        }

        // One grace-end read per call (the model memoizes it
        // per instance): the paused state is the same instant
        // compared against now, exactly dunningPausedAt()'s
        // rule, without a second episode lookup. A null grace
        // end on a past_due business is the fail-closed state
        // (no episode evidence): the gate is paused, so the
        // line says paused.
        $graceEnd = $business->dunningGraceEndsAt();

        if ($graceEnd === null || now()->greaterThanOrEqualTo($graceEnd)) {
            return self::pausedLine();
        }

        return 'Your QR code keeps working until '
            .$business->localTime($graceEnd)->format('F j, Y')
            .'. Update your card by then to keep it on.';
    }
}
