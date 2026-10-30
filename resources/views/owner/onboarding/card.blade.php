@extends('layouts.onboarding')

@section('title', 'Add your card')

@section('content')
    @include('owner.onboarding._progress')

    {{-- OB-3 Card (design pack Section 4.4 + benchmark O-3/O-4).
         The activation step, understood before it is asked for.
         The form is the billing page's Square capture partial
         (one form, two hosts; OB-U4 moves it to the shared
         partials home with the FE-CARD-01 guard intact). --}}
    @if ($business->hasFriendRewardText())
        <h1 class="type-title-2 text-text-1">Your code already works. This step keeps it working.</h1>
    @else
        <h1 class="type-title-2 text-text-1">Set your reward first, then this step keeps your code working.</h1>
    @endif
    <p class="type-body mt-2 text-text-2">Your first 5 visits are free, and your code works for them with no card at all. After those 5, your code pauses until a card is on file. Add yours now and nothing interrupts your first customers.</p>
    <p class="type-body mt-2 text-text-2">Your first 5 visits are free, so nothing is charged today. After that it is ${{ number_format($plan->perVisitPriceCents() / 100, $plan->perVisitPriceCents() % 100 === 0 ? 0 : 2) }} when a referred friend visits, one charge a month for that month's visits, never more than ${{ number_format($plan->monthly_cap_cents / 100, 0) }} in visit charges. Nothing is charged until your free visits are used.</p>

    @if ($cardSaved)
        {{-- O-4: the success state renders before advancing.
             The benchmark's sentence carries an em dash; the
             no-em-dash rule wins and it renders as two
             sentences (flagged for ratification).
             FLOW-STATE TRUTH train: "Your code is on" renders
             only when the gate itself is on (flowActive, from
             flowIsActive()). Card-first through the checklist,
             the reward leg is still open and the public landing
             still answers not-active, so that state names the
             remaining gate instead — the same sentence the
             billing page uses for this exact state
             (F2-BILL-02).
             ACTIVATION-REDESIGN-OWNED (coordinator steer
             2026-10-30): the not-on sentences in these panels
             depend on card-gate semantics the activation
             redesign is redefining; panel SELECTION derives
             from the gate predicates and survives — final
             wording belongs to the redesign's design pass. --}}
        <div class="panel mt-6">
            @if ($business->flowIsActive())
                <p class="font-semibold text-text-1">✓ Your code is on. Nothing is charged today. Your first 5 visits are free.</p>
            @elseif (! $business->hasFriendRewardText())
                <p class="font-semibold text-text-1">✓ Card saved. Your code turns on once you set your friend reward.</p>
            @else
                <p class="font-semibold text-text-1">✓ Card saved. Nothing is charged today.</p>
            @endif
            <p class="mt-4"><a class="btn btn-primary" href="{{ route('onboarding.step', $next) }}">Continue</a></p>
        </div>
    @elseif ($done)
        <div class="panel mt-6">
            @if ($business->flowIsActive())
                <p class="text-text-1"><span class="font-semibold">✓ Card on file.</span> Your code is on.</p>
            @elseif (! $business->hasFriendRewardText())
                <p class="text-text-1"><span class="font-semibold">✓ Card on file.</span> Your code turns on once you set your friend reward.</p>
            @else
                <p class="text-text-1"><span class="font-semibold">✓ Card on file.</span></p>
            @endif
            <p class="mt-4"><a class="btn btn-primary" href="{{ route('onboarding.step', $next) }}">Continue</a></p>
        </div>
    @else
        @if ($cardFailed)
            <div class="panel mt-6">
                <p class="text-text-1">That card did not go on. Nothing was charged. Check the number and try again, or use a different card.</p>
            </div>
        @endif

        <div id="card-form" data-billing-card-form class="mt-4"></div>
        @include('partials.billing-card-form', [
            'action' => route('onboarding.card.store'),
            'mountId' => 'card-form',
            'submitLabel' => 'Add card',
        ])

        {{-- ACT-CARD-05 (census C-07): the removal truth under
             the allowance model — counting is claimed only
             while free visits last. --}}
        <p class="type-body-sm mt-4 text-text-2">You can take your card off any time in Settings → Billing, or end your program any time in Settings → Account. Taking the card off pauses charges. Visits keep counting while your free visits last, and your code pauses when they run out if no card is on file.</p>

        {{-- Hybrid skip rule (affordance train, 2026-10-29): a
             skip with a consequence renders as a full secondary
             button, not a text action. ACT-CARD-06 (census
             C-08): the consequence is now the honest one —
             skipping is safe for the 5 free visits and costly
             only after them — and the button keeps its form. --}}
        <p class="mt-6">
            <a class="btn btn-secondary" href="{{ route('onboarding.step', $next) }}">Skip for now</a>
        </p>
        <p class="type-body-sm mt-2 text-text-2">Your code works for your first 5 visits with no card. You can add a card any time in Settings → Billing.</p>
    @endif
@endsection
