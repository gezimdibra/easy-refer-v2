{{--
    Replace and remove for the card on file (billing UI spec
    Section 2 Block C, build unit BU-4). Both are the Section
    8.7 step-up action class (spec 9.9): their routes carry the
    step-up middleware, so posting without a fresh verification
    sends the owner through the challenge first. Replace opens
    the SDK capture form in place (its own mount); remove asks
    first with the BILL-08 confirmation, and the removal itself
    is a plain server-bound post, so it carries the app's
    data-loading busy state like every other settings form.
--}}
<div class="mt-4" x-data="{ replacing: false, confirmingRemove: false }">
    <div class="flex flex-wrap gap-3">
        <button type="button" class="btn btn-secondary min-h-12" @click="replacing = !replacing">Replace card</button>
        <button type="button" class="btn btn-secondary min-h-12" @click="confirmingRemove = !confirmingRemove">Remove card</button>
    </div>

    <div x-show="replacing" x-cloak>
        <div id="card-form-replace" class="mt-4"></div>
        @include('owner.settings.partials.billing-card-form', [
            'action' => route('settings.billing.card.replace'),
            'mountId' => 'card-form-replace',
            'submitLabel' => 'Replace card',
        ])
    </div>

    <div x-show="confirmingRemove" x-cloak class="mt-4 rounded-md border border-line bg-surface-2 p-3">
        {{-- BILL-08 branched by billing state (Amendment 38,
             persona F-2 unit): the keeps-counting clause is true
             only while visits actually count (billing active, or
             past_due inside the grace window, on an active
             business). In the paused state the flow is paused;
             on a closed or disabled business it is off. The
             string's valid states are recorded in Amendment 38. --}}
        @if ($business->isClosed())
            {{-- TS3-03 (Sweep-03, Amendment 52): the closed
                 sub-state of the off form. A closed business
                 cannot add a card (the card routes are refused
                 while closed) and its charges do not pause until
                 a card: pre-closure owed months are collected
                 exactly once each, then billing stops forever.
                 The card-action and resumption clauses are
                 dropped; the owed half stays, true in both
                 sub-states. The disabled form below keeps them:
                 a disabled business can be re-enabled and can
                 act on its card. --}}
            <p class="text-text-1">Remove this card? Your QR code and customer links are off. Visits are not counting and rewards are not being earned while your program is off. The amount you owe stays on your account.</p>
        @elseif (! $business->isActive())
            <p class="text-text-1">Remove this card? Your QR code and customer links are off. Visits are not counting and rewards are not being earned while your program is off. Charges pause until you add a card, and the amount you owe stays on your account.</p>
        @elseif ($business->billing_status === 'past_due' && $business->dunningPausedAt())
            <p class="text-text-1">Remove this card? Your QR code and customer links are paused until you update your card. Visits are not counting and rewards are not being earned while your program is paused. Charges resume when you add a card, and the amount you owe stays on your account.</p>
        @elseif (! $business->flowIsActive())
            {{-- BILL-08 RE-KEY (Amendment 45): the keeps-counting
                 form is keyed on the FULL flowIsActive picture,
                 not billing state alone. A billing-active
                 business whose flow is off for the reward-text
                 reason (the only remaining inactive cause on an
                 active, unpaused business) is told the truth:
                 visits are not counting until the flow is on. --}}
            <p class="text-text-1">Remove this card? Your QR code is not active yet, so visits are not counting and rewards are not being earned. Charges pause until you add a card, and the amount you owe stays on your account.</p>
        @elseif ($business->allowanceLeft() === 0)
            {{-- ACT-BILL-08C (activation redesign, census
                 C-19): removal at a spent allowance pauses the
                 code — warned BEFORE the owner confirms, in
                 the branch order of Section 2.4 (the closed,
                 off, and past-due-paused forms above keep
                 precedence, as their causes do). --}}
            <p class="text-text-1">Remove this card? Your free visits are used up, so your code pauses until you add a card again. The amount you owe stays on your account.</p>
        @else
            {{-- ACT-BILL-08B: the keeps-counting form survives
                 only in its allowance-branched form (census
                 R9): removal leaves free visits, and the form
                 names the count. --}}
            <p class="text-text-1">Remove this card? Visits keep counting while your free visits last ({{ $business->allowanceLeft() }} left). Charges pause until you add a card, and the amount you owe stays on your account.</p>
        @endif
        <form method="POST" action="{{ route('settings.billing.card.remove') }}" data-loading class="mt-3 flex flex-wrap gap-3">
            @csrf
            <button type="submit" class="btn btn-danger min-h-12">Remove card</button>
            <button type="button" class="btn btn-secondary min-h-12" @click="confirmingRemove = false">Keep card</button>
        </form>
    </div>
</div>
