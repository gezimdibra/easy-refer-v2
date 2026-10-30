@extends('layouts.onboarding')

@section('title', 'Finish setup')
@section('hide_skip_setup', '1')

@section('content')
    {{-- OB-6 Finish (design pack Section 4.7): one of two
         truthful states, computed from flowIsActive() and the
         step predicates at render, never from the path taken.
         Either door passes the screen and records completion
         (OB-U6 handoff: the complete action stamps the fact). --}}
    @if ($live)
        <h1 class="type-title-2 text-text-1">✓ You're live, {{ $business->name }}.</h1>
        <p class="type-body mt-2 text-text-2">Your code works. Put your poster where customers can see it, and every friend who visits counts, the first 5 are free.</p>
        @unless ($business->hasCardOnFile())
            {{-- ACT-FIN-02 (activation redesign, census C-10):
                 the cardless live state carries the allowance
                 count and its action on the owner's first live
                 screen. {N} is allowanceLeft() at render. --}}
            <p class="type-body mt-2 text-text-2">Your code is live on your free visits. You have {{ $business->allowanceLeft() }} of your 5 free visits left. Add a card any time so it stays on after that.</p>
        @endunless
        @if ($testDone)
            <p class="type-body mt-2 text-text-2">You already proved it: the join you just made came through your own poster.</p>
        @else
            <p class="type-body mt-2 text-text-2">Want proof it works? You can scan your own code any time, it is on your QR page.</p>
        @endif
        @unless ($emailVerified)
            {{-- O-1: deferring Verify never hides it. Quiet,
                 non-blocking, on the live finish only. Lane
                 drafted under the O-1 ruling; in the
                 ratification list. --}}
            <p class="type-body mt-2 text-text-2">Your email is not verified yet. Verify it so your receipts and password resets reach you.</p>
        @endunless
        <form method="POST" action="{{ route('onboarding.complete') }}" class="mt-8" data-loading>
            @csrf
            <input type="hidden" name="door" value="dashboard">
            <button type="submit" class="btn btn-primary">Go to your dashboard</button>
        </form>
    @elseif ($pastDuePaused ?? false)
        {{-- Two-key re-review Finding 2 (2026-10-30): the
             past_due PAUSED state is not the no-card state.
             Saving a card does not unpause it (CardOnFileService
             flips only none/setup_incomplete); the business
             returns only when a charge succeeds, so this branch
             states the paused truth and precedes every card
             branch. A still-missing reward is named too — after
             the charge succeeds the code stays off without it. --}}
        <h1 class="type-title-2 text-text-1">You're set up, but your code is paused.</h1>
        <p class="type-body mt-2 text-text-2">{{ \App\Support\BillingCopy::pausedCauseClause($business) }}, so your code is paused. {{ \App\Support\BillingCopy::pausedRemedyLine() }}</p>
        @if ($rewardsMissing ?? false)
            <p class="type-body mt-2 text-text-2">You also still need to set your reward.</p>
        @endif
        <div class="mt-8 flex flex-wrap items-center gap-4">
            <form method="POST" action="{{ route('onboarding.complete') }}" data-loading>
                @csrf
                <input type="hidden" name="door" value="billing">
                <button type="submit" class="btn btn-primary">Update your card</button>
            </form>
            @if ($rewardsMissing ?? false)
                <form method="POST" action="{{ route('onboarding.complete') }}" data-loading>
                    @csrf
                    <input type="hidden" name="door" value="rewards">
                    <button type="submit" class="btn btn-ghost">Set your rewards</button>
                </form>
            @endif
            <form method="POST" action="{{ route('onboarding.complete') }}" data-loading>
                @csrf
                <input type="hidden" name="door" value="dashboard">
                <button type="submit" class="btn btn-ghost">Go to your dashboard</button>
            </form>
        </div>
    @elseif (($rewardsMissing ?? false) && ($cardGateOpen ?? false))
        {{-- FLOW-STATE TRUTH train: BOTH activation gates are
             open. The rewards-only copy ("set your reward and
             your code turns on") is false here — the landing
             stays off until the card leg also closes — so this
             branch names both remaining gates, in the setup
             order, and still routes to rewards first.
             ACTIVATION-REDESIGN-OWNED (coordinator steer
             2026-10-30): this sentence's truth depends on
             card-gate semantics, which the activation redesign
             (no-card businesses live for their first 5 free
             visits) is redefining — the branch SELECTION here
             derives from the gate predicates and survives; the
             final WORDING belongs to the redesign's design
             pass, not this train. --}}
        <h1 class="type-title-2 text-text-1">You're set up, two steps from live.</h1>
        <p class="type-body mt-2 text-text-2">Two things are still missing: your rewards and a card on file. Set your reward and add a card, and your code turns on; visits are free until you pass 5.</p>
        <div class="mt-8 flex flex-wrap items-center gap-4">
            <form method="POST" action="{{ route('onboarding.complete') }}" data-loading>
                @csrf
                <input type="hidden" name="door" value="rewards">
                <button type="submit" class="btn btn-primary">Set your rewards</button>
            </form>
            <form method="POST" action="{{ route('onboarding.complete') }}" data-loading>
                @csrf
                <input type="hidden" name="door" value="dashboard">
                <button type="submit" class="btn btn-ghost">Go to your dashboard</button>
            </form>
        </div>
    @elseif ($rewardsMissing ?? false)
        {{-- Train F2 (Amendment 40, F2-FIN-01): not live because
             the rewards were never set. This branch precedes the
             card branch: rewards come before the card in the
             setup order, and a card-holding textless owner must
             never be told the card is the missing step.
             FLOW-STATE TRUTH train: this branch now renders
             only when the card leg already holds
             (billingAllowsFlow), so its turns-on claim is the
             gate's own truth. --}}
        <h1 class="type-title-2 text-text-1">You're set up, one step from live.</h1>
        <p class="type-body mt-2 text-text-2">Everything is ready except your rewards. Set your reward and your code turns on right away; visits are free until you pass 5.</p>
        <div class="mt-8 flex flex-wrap items-center gap-4">
            <form method="POST" action="{{ route('onboarding.complete') }}" data-loading>
                @csrf
                <input type="hidden" name="door" value="rewards">
                <button type="submit" class="btn btn-primary">Set your rewards</button>
            </form>
            <form method="POST" action="{{ route('onboarding.complete') }}" data-loading>
                @csrf
                <input type="hidden" name="door" value="dashboard">
                <button type="submit" class="btn btn-ghost">Go to your dashboard</button>
            </form>
        </div>
    @elseif (($blockReason ?? null) === 'allowance_exhausted')
        {{-- ACT-FIN-03 (activation redesign, census C-09):
             the burned-before-finish owner. This owner WAS
             live: the retired card branch's "one step from
             live" framing is deleted with its premise, and
             this paused form renders only in this one state. --}}
        <h1 class="type-title-2 text-text-1">Your code was live, and it is paused now.</h1>
        <p class="type-body mt-2 text-text-2">Your 5 free visits are used up. Add a card to turn your code back on. Nothing is charged when you add your card.</p>
        <div class="mt-8 flex flex-wrap items-center gap-4">
            <form method="POST" action="{{ route('onboarding.complete') }}" data-loading>
                @csrf
                <input type="hidden" name="door" value="billing">
                <button type="submit" class="btn btn-primary">Add a card now</button>
            </form>
            <form method="POST" action="{{ route('onboarding.complete') }}" data-loading>
                @csrf
                <input type="hidden" name="door" value="dashboard">
                <button type="submit" class="btn btn-ghost">Go to your dashboard</button>
            </form>
        </div>
    @else
        {{-- Design pass Section 3.1: a dunning-paused owner at
             finish gets the dashboard door only — the past-due
             chrome owns that state everywhere else. (Closed
             and disabled never reach this view; the controller
             redirects them to the dashboard.) --}}
        <h1 class="type-title-2 text-text-1">You're set up.</h1>
        <div class="mt-8 flex flex-wrap items-center gap-4">
            <form method="POST" action="{{ route('onboarding.complete') }}" data-loading>
                @csrf
                <input type="hidden" name="door" value="dashboard">
                <button type="submit" class="btn btn-primary">Go to your dashboard</button>
            </form>
        </div>
    @endif

    {{-- OB-VAL-01 (Amendment 44): the optional value panel,
         shared by all three finish branches above; it renders
         only while the average ticket is unset. --}}
    @if ($showValuePanel ?? false)
        @include('owner.onboarding.partials.value-panel')
    @endif

    {{-- SK-6 [SRC-01] (Amendment 67): the optional source
         panel, shared by all finish branches; it renders only
         while no source is recorded and the business is not a
         claimed one. --}}
    @if ($showSourcePanel ?? false)
        @include('owner.onboarding.partials.source-panel')
    @endif
@endsection
