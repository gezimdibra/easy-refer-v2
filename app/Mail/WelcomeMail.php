<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * The welcome email, repurposed (owner amendment, 2026-10-29;
 * copy-inventory Amendment 59): it is no longer the signup
 * email. Registration sends exactly one email, the framework
 * verification notification, and this mailable is the
 * UNVERIFIED NUDGE: WelcomeNudgeService sends it exactly once
 * per account, at 24+ hours old, only while the address is
 * still unverified. An owner who verifies inside day one never
 * receives it; verification resends never send it (they resend
 * the verification notification). Because the nudge renders
 * synchronously at its send (Mail::send, never queued), the
 * envelope subject, the title, and the body's steps-left count
 * are all computed at send time from the live facts; OB-04's
 * disposition (subject computed from the same facts the body
 * branches on, never frozen at queue time) is preserved and
 * strengthened: there is no queue-time freeze left to regress.
 * The verify button carries a FRESH signed verification link
 * minted in content() at that send-time render (the signup
 * link, 60 minutes by config, is long expired by hour 24), so
 * clicking it verifies the address exactly as the framework
 * notification's link does. The confirmed-state branches stay:
 * they are the render-time truth for the narrow race where an
 * owner verifies between the nudge run's eligibility read and
 * its render, and every branch's count is true in its state.
 *
 * TS-PM-01 fix (truth sweep 2026-10-29 PM): the steps-left
 * count names ACTIVATION blockers only. Email verification
 * gates nothing: Business::flowIsActive() is status plus
 * friend reward text plus billing, and hasVerifiedEmail is
 * read by no flow, QR, or billing path. Verification is this
 * mail's own ask, carried as its own sentence with the reason
 * the product already gives on its other surfaces (receipts
 * and password resets), never as a step before the code works.
 * TS-PM-02 fix, same sweep: the trailing reward line branches
 * on the one-reward configuration exactly as the poster
 * system does (unified reads the one reward; a Settings-split
 * business reads both rewards named) and on whether the
 * reward is already set, so no branch instructs an owner to
 * set a reward that is already set, in a form that no longer
 * exists.
 */
class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    /**
     * The activation steps the "before your code works" count
     * names: the reward_text leg, plus the card ONLY in the
     * state where the card is the step that keeps the code on
     * (no card on file and the free allowance spent; census
     * C-28) — the legs of Business::flowIsActive() an owner
     * can still act on (the status leg is the nudge service's
     * deferral, not a step). These are read from
     * SetupChecklist::for() itself, the one computation the
     * onboarding steps, the checklist card, and the Action
     * Center all read (amendment instruction: never a parallel
     * definition). Email verification is a required SETUP step
     * on that checklist, but it is not an activation blocker:
     * a reward-set owner who never verifies has a fully live
     * code, so verification never enters this count (TS-PM-01).
     * Poster and test-referral are onboarding steps too, but
     * they are not blockers either, so they never enter it.
     * TS3-06 (Amendment 52) stands in its exactness: the count
     * is exact against the legs it names, never an undercount
     * or an overcount. A business-less user (no production
     * path produces one) counts both business legs as
     * outstanding.
     *
     * @param  array<string, bool>|null  $checklist
     */
    private function stepsLeft(?array $checklist = null): int
    {
        $business = $this->user->business;

        if ($business === null) {
            return 2;
        }

        $checklist ??= \App\Support\SetupChecklist::for($business, $this->user);

        // Activation redesign (census C-28): the card counts
        // as a step left ONLY when the business needs it to
        // keep its code on — no card AND the free allowance
        // spent (flowBlockReason 'allowance_exhausted'). A
        // cardless owner with the reward set and allowance
        // left has ZERO steps left before their code works,
        // because it works.
        $cardStepLeft = ! ($checklist['card_on_file'] ?? false)
            && $business->allowanceLeft() === 0;

        return (int) $cardStepLeft
            + (int) (! ($checklist['reward_text'] ?? false));
    }

    /**
     * The trailing reward-and-print line (TS-PM-02), composed
     * once here so every branch of the template carries the
     * same state truth. The branching follows the poster
     * system (Business::posterHeadline): a unified business
     * reads the one reward; a split business (the Settings
     * opt-in, Business::rewardsAreSplit) reads both rewards
     * named. A business whose reward is already set is told
     * that it is set, never instructed to set it again.
     *
     * @param  array<string, bool>|null  $checklist
     */
    private function trailingLine(?array $checklist = null): string
    {
        $business = $this->user->business;

        if ($business === null) {
            return 'Set your reward, then print your poster and tent card from the QR page and put them out.';
        }

        $checklist ??= \App\Support\SetupChecklist::for($business, $this->user);
        $hasReward = (bool) ($checklist['reward_text'] ?? false);
        $split = $business->rewardsAreSplit();

        if (! $hasReward) {
            return $split
                ? 'Set your friend reward and your referrer reward, then print your poster and tent card from the QR page and put them out.'
                : 'Set your reward, then print your poster and tent card from the QR page and put them out.';
        }

        return $split
            ? 'Your friend reward and your referrer reward are set. Then print your poster and tent card from the QR page and put them out.'
            : 'Your reward is set. Then print your poster and tent card from the QR page and put them out.';
    }

    /**
     * The headline every surface of this mail carries: the
     * envelope subject and the HTML <title> are this one
     * string (TS3-04: the title was never re-keyed when OB-04
     * branched the envelope, so a fully set-up owner got the
     * plain subject over a one-step-left title). The count is
     * exact: zero steps gets the plain form, one step keeps
     * the shipped one-step form, more steps are counted.
     */
    private function headline(): string
    {
        return match ($this->stepsLeft()) {
            0 => 'Welcome to Easy Refer',
            1 => 'Welcome to Easy Refer: one step left before your code works',
            default => 'Welcome to Easy Refer: '.$this->stepsLeft().' steps left before your code works',
        };
    }

    public function envelope(): Envelope
    {
        // OB-04 (Amendment 45): the subject is computed here,
        // at render time, from the same facts content()
        // branches the body on — never frozen at queue time.
        return new Envelope(subject: $this->headline());
    }

    public function content(): Content
    {
        $business = $this->user->business;
        // One SetupChecklist computation drives every branch
        // and the count (see stepsLeft()): reward_text is that
        // class's own key, and the card enters the count only
        // through the allowance derivation in stepsLeft()
        // (TS-PM-01, as re-derived by census C-28).
        $checklist = $business !== null
            ? \App\Support\SetupChecklist::for($business, $this->user)
            : null;
        $verified = $this->user->hasVerifiedEmail();
        // Train A2 (F-10): the confirmed branch's claim ("your
        // code is ready to go out") is true only once the flow
        // is actually on; email confirmation alone never turns
        // the code on. Train F2 (F2-WEL-01): the branch splits
        // on the reward leg too, so an owner who never set
        // rewards is never told the code is ready while it is
        // off.
        //
        // Activation redesign (census C-29): the branch key is
        // no longer the card. The activation step an owner
        // still owes is the flowBlockReason's remedy: reward
        // missing -> the rewards step (in any card state);
        // else the flow live -> the QR action (a cardless
        // owner whose code works on its free visits is sent
        // to their code, not to a card form); else the reason
        // is the exhausted allowance -> billing, 'Add your
        // card'. The one-step-left body in the blade reads the
        // same derivation.
        $hasFriendReward = $checklist !== null && ($checklist['reward_text'] ?? false);
        $flowActive = $business !== null && $business->flowIsActive();
        $rewardsMissing = $verified && ! $hasFriendReward;
        // TS-PM-01: the unverified callout names the first open
        // ACTIVATION leg as its steps-left step, in the F2
        // pattern the Action Center and the QR inactive notice
        // already use (the reward leg first, in the checklist's
        // own order; the card leg only once the reward is set
        // and the allowance is spent). Verification is named
        // nowhere in that count; the callout carries it as a
        // separate sentence.
        $nextActivationStep = ! $hasFriendReward ? 'set your rewards' : 'add your card';

        return new Content(view: 'emails.welcome', with: [
            'business' => $business,
            'stepsLeft' => $this->stepsLeft($checklist),
            'mailTitle' => $this->headline(),
            'verified' => $verified,
            'flowActive' => $flowActive,
            'hasReward' => $hasFriendReward,
            'nextActivationStep' => $nextActivationStep,
            'trailingLine' => $this->trailingLine($checklist),
            'rewardsMissing' => $rewardsMissing,
            'actionUrl' => ! $verified
                ? $this->verificationUrl()
                : ($rewardsMissing ? route('settings.rewards') : ($flowActive ? route('owner.qr') : route('settings.billing'))),
            'actionLabel' => ! $verified
                ? 'Verify your email address'
                : ($rewardsMissing ? 'Set your rewards' : ($flowActive ? 'Get your QR code' : 'Add your card')),
            'dashboardUrl' => route('dashboard'),
            'faqUrl' => route('faq'),
        ]);
    }

    /**
     * The signed verification link, built exactly the way the
     * framework's VerifyEmail notification builds it (spec
     * Section 11.1: emailed links derive from APP_URL).
     */
    private function verificationUrl(): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id' => $this->user->getKey(),
                'hash' => sha1($this->user->getEmailForVerification()),
            ]
        );
    }
}
