{{--
    The point-of-distribution truth (coherence Phase 2, G1;
    copy Amendment 24 QR-INACTIVE-01): while the business's
    public flow is inactive, the surfaces that hand the code
    to the world — the QR page and every poster page's screen
    chrome — say so in plain words, naming the missing step,
    before the owner prints. One partial, so the words cannot
    drift between surfaces (the BILL-PD-01 discipline). The
    including surface owns the conditional and the container;
    this partial owns the sentence and its one branch:

    - the account is active but billing is not (the card is
      the missing step): QR-INACTIVE-01a, with the Add a card
      action into Billing;
    - the account itself is not active (paused or closed —
      no action on these surfaces resolves it): QR-INACTIVE-01b,
      words only;
    - past_due past its grace end (two-key Finding 2,
      2026-10-30): the paused truth — update the card AND
      complete a successful charge — which precedes both card
      branches, because a saved card alone never unpauses.
--}}
@if ($business->isActive() && $business->billingIsPastDuePaused())
    {{-- Two-key re-review Finding 2 (2026-10-30): the past_due
         PAUSED state is not the no-card state, whatever the
         reward leg says — a saved card never unpauses it; only
         a successful charge does. This branch precedes every
         card/reward branch and states the paused truth. --}}
    <p>This code is paused. {{ \App\Support\BillingCopy::pausedCauseClause($business) }}. {{ \App\Support\BillingCopy::pausedRemedyLine() }} Until then, anyone who scans it sees a "not active" message.</p>
    <p class="mt-2"><a class="text-action" href="{{ route('settings.billing') }}#card">Update your card</a></p>
@elseif ($business->isActive() && ! $business->hasFriendRewardText() && $business->billingIsNoCard())
    {{-- FLOW-STATE TRUTH train: BOTH legs are open, so the
         reward-only "to turn it on" claim would be false; this
         branch names both missing steps. Two-key Finding 2:
         the card leg here is the genuine no-card state
         (billingIsNoCard), not the lumped billingAllowsFlow. --}}
    <p>This code is not active yet. Set your friend reward and add a card to turn it on. Until then, anyone who scans it sees a "not active" message.</p>
    <p class="mt-2"><a class="text-action" href="{{ route('settings.rewards') }}">Set your rewards</a></p>
@elseif ($business->isActive() && ! $business->hasFriendRewardText())
    {{-- Train F2 (Amendment 40, F2-GATE-01): the rewards leg of
         activation is checked FIRST, in the checklist's own
         order — an owner who already saved a card must never be
         told to add one. FLOW-STATE TRUTH train: this branch
         now renders only when the card leg already holds
         (billingAllowsFlow), so its turns-on claim is the
         gate's own truth. --}}
    <p>This code is not active yet. Set your friend reward to turn it on. Until then, anyone who scans it sees a "not active" message.</p>
    <p class="mt-2"><a class="text-action" href="{{ route('settings.rewards') }}">Set your rewards</a></p>
@elseif ($business->isActive() && $business->flowBlockReason() === 'allowance_exhausted')
    {{-- ACT-QR-02 (activation redesign, census C-12): the
         card branch survives in exactly one cause — the code
         is not "not active yet", it is paused after working.
         Every other active-business cause (dunning pause)
         falls to the words-only form below: no surface here
         names a remedy that is not this state's. --}}
    <p>Your code is paused. Your 5 free visits are used up, so new customers cannot join until you add a card.</p>
    <p class="mt-2"><a class="text-action" href="{{ route('settings.billing') }}#card">Add a card</a></p>
@elseif ($business->isActive())
    <p>This code is not active right now, so new customers cannot join.</p>
@else
    <p>This code is not active right now, so new customers cannot join.</p>
@endif
