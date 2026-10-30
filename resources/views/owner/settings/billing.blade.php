@extends('layouts.app')

@section('title', 'Billing')


@section('content')
    <h1 class="type-title-2 text-text-1">Billing</h1>

    <p class="type-body mt-2 max-w-[68ch] text-text-2">Your plan, your card, and what you have been charged.</p>

    <div class="mt-6 lg:flex lg:items-start lg:gap-8">
        <div class="lg:w-[220px] lg:flex-none">
            @include('owner.settings.nav')
        </div>
        <div class="mt-6 min-w-0 flex-1 space-y-4 lg:mt-0">

    {{-- Block A: status banners (billing UI spec Section 2).
         Each renders only in its named state; no empty chrome.
         The past-due banner is keyed to billing_status, which
         only the webhook lane (BU-8) can set, so it is inert
         until then by construction. --}}
    @if ($business->billing_status === 'past_due')
        {{-- R2 BILL: severity gets banner chrome, stacked at
             every width (design section 6 build unit 2). --}}
        <div class="banner banner-error flex-col items-stretch" role="region" aria-label="Billing status">
            @include('partials.billing-past-due', ['cardHref' => '#card'])
        </div>
    @endif

    @if ($business->billing_status === 'active' && $card === null)
        {{-- ACT-BILL-03a / 03b (activation redesign, census
             C-18): "visits keep counting" is true only while
             free visits last; at zero the code is paused and
             the banner says so. --}}
        <div class="banner banner-warning" role="region" aria-label="Billing status">
            @if ($business->allowanceLeft() > 0)
                <p class="text-text-1">No card on file. Visits keep counting while your free visits last ({{ $business->allowanceLeft() }} left), and the amount builds up here.</p>
            @else
                <p class="text-text-1">No card on file, and your free visits are used up, so your code is paused. Add a card to turn it back on.</p>
            @endif
        </div>
    @endif

    @if (! $chargesEnabled)
        <section class="panel" aria-label="Billing status">
            <p class="text-text-1">Charges are not switched on yet. Visits still count, and this page keeps the running total.</p>
        </section>
    @endif

    {{-- Block B: plan (DASH-14 heading, DASH-15 line; amounts
         render from the plan row). --}}
    <section class="panel">
        <h2 class="type-title-3 text-text-1">Current plan</h2>
        <p class="mt-2 font-semibold text-text-1">{{ $plan->name }}</p>
        <p class="mt-1 text-text-2">{{ '$'.number_format($plan->perVisitPriceCents() / 100, 2) }} per visit. Never more than {{ '$'.number_format($plan->monthly_cap_cents / 100, 0) }} a month in visit charges.</p>
        {{-- E-01b (FE-CANCEL-01): the Billing pointer to the
             End my program flow, the bridge behind the frozen
             homepage's "Cancel anytime" promise. Renders only
             while the business is active; a closed business
             renders the E-11 states below instead. --}}
        @if (! $business->isClosed())
            <p class="type-body-sm mt-3 text-text-2">To stop billing, end your program in Settings, under Account. Your final bill covers the visits and texts you already have.</p>
        @endif
        {{-- ID-11 [COUP-09] (Amendment 53): the coupon billing
             ruling in the owner's money surface, true in every
             state because it is a property of the channel. --}}
        <p class="type-body-sm mt-3 text-text-2">Coupon claims are never charged. You only pay when a referred customer walks in and redeems.</p>
    </section>

    @if ($business->isClosed())
        {{-- E-11 (FE-CANCEL-01): the closed Billing states,
             branched on the charger's own reads (pending final
             record, unclaimed balance, or neither). --}}
        <section class="panel" aria-label="Program ended">
            @if (($closedPendingCents ?? 0) > 0)
                <p class="text-text-1">We are confirming your final charge of {{ '$'.number_format($closedPendingCents / 100, 2) }}. This page updates when it finishes.</p>
            @elseif (($closedOwedCents ?? 0) > 0)
                <p class="text-text-1">Your program ended on {{ $business->localTime($business->closed_at)->format('M j, Y') }}. {{ '$'.number_format($closedOwedCents / 100, 2) }} is still owed from before you ended. We try the card on file once a month. You can replace the card below.</p>
            @else
                <p class="text-text-1">Your program ended on {{ $business->localTime($business->closed_at)->format('M j, Y') }}. These are your final charges. Nothing more will be charged.</p>
            @endif
        </section>
    @endif

    {{-- Block C: card on file (DASH-14). The capture form
         itself lands in BU-4 (Square Web Payments SDK behind
         the step-up for replace and remove); the mount point
         below is its home. A pending row renders the BILL-05
         confirming state, never as a card. --}}
    <section class="panel" id="card">
        <h2 class="type-title-3 text-text-1">Card on file</h2>
        @if ($card !== null && ! $business->hasFriendRewardText())
            {{-- Train F2 (Amendment 40, F2-BILL-02): the card is
                 saved but the code is still off for want of the
                 friend reward — named here, on the page the
                 owner lands on right after the card save that
                 used to half-activate them silently. Renders
                 only in the card-present + textless state.
                 Truth sweep 2026-10-30, T-1: the paused
                 precedence branch below comes FIRST — for a
                 past_due-PAUSED business the dunning leg
                 precedes the reward leg in flowBlockReason(),
                 so setting the reward does NOT turn the code
                 on; the well states the paused truth and the
                 remedy, and names the reward as ALSO still
                 needed. The F2-BILL-02 turns-on string renders
                 only when NOT past-due-paused. --}}
            <div class="well mt-3">
                @if ($business->billingIsPastDuePaused())
                    <p class="text-text-1">Your card is saved, but your code is paused. {{ \App\Support\BillingCopy::pausedRemedyLine() }}</p>
                    <p class="mt-2 text-text-1">You also still need to set your reward.</p>
                @else
                    <p class="text-text-1">Your card is saved. Your code turns on once you set your friend reward.</p>
                @endif
                <p class="mt-2"><a class="font-semibold text-link hover:underline" href="{{ route('settings.rewards') }}">Set your rewards</a></p>
            </div>
        @endif
        @if ($card !== null)
            {{-- R2 BILL: the wallet row (design section 6). The
                 state pill reuses the controller's SV-02
                 card-state values ($cardExpired / $cardExpiresSoon);
                 the predicate is not forked here. --}}
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <span class="flex h-11 items-center rounded-md bg-surface-2 px-3 font-semibold text-text-1">{{ $card->card_brand }}</span>
                <span class="text-text-1">•••• {{ $card->last4 }}</span>
                <span class="type-body-sm text-text-2">Expires {{ str_pad((string) $card->exp_month, 2, '0', STR_PAD_LEFT) }}/{{ $card->exp_year }}</span>
                @if ($cardExpired)
                    <span class="pill pill-error">Expired</span>
                @elseif ($cardExpiresSoon)
                    <span class="pill pill-warning">Expires soon</span>
                @else
                    <span class="pill pill-success">On file</span>
                @endif
            </div>
            @if ($cardExpired)
                <p class="mt-2 text-text-1">This card has expired. Replace it to keep monthly charges going.</p>
            @elseif ($cardExpiresSoon)
                <p class="mt-2 text-text-1">This card expires soon. Replace it to keep monthly charges going.</p>
            @endif
            @include('owner.settings.partials.billing-card-actions')
        @elseif ($pendingCard !== null)
            <p class="mt-2 text-text-1">We are still confirming your card with Square. This page will update on its own. Please do not add the card again.</p>
        @else
            {{-- The no-card panel speaks to the business's actual
                 state (T3-F5): the turn-on-your-QR-code framing is
                 only true before the first visit; once visits have
                 used some or all of the free allowance, the QR code
                 already works and the honest ask is the card that
                 restarts monthly charges, with the real free count. --}}
            {{-- ACT-BILL-01 / 02 (activation redesign, census
                 C-16/C-17; design pass Section 3.3): the panel
                 re-keys on the gate's own derivation —
                 allowanceLeft() and flowBlockReason(), never
                 the settlement-lagged free_visits_remaining
                 column. ON form while allowance remains (the
                 code is on; the card ask is keeping it on);
                 PAUSED form only in the allowance-exhausted
                 state, the one state where a turn-on heading
                 is true again. The F2 subline survives attached
                 to the rewards cause only. --}}
            @if ($business->billingIsPastDuePaused())
                {{-- Merge composition (activation merge, 2026-10-30):
                     main's flow-state Finding 2 precedence — a
                     past_due PAUSED business that removed its failed
                     card reaches this no-card panel, and the paused
                     truth (successful-charge remedy) precedes every
                     card framing below. --}}
                <p class="mt-2 font-semibold text-text-1">Your QR code is paused</p>
                <p class="type-body-sm mt-1 text-text-2">{{ \App\Support\BillingCopy::pausedCauseClause($business) }}. {{ \App\Support\BillingCopy::pausedRemedyLine() }}</p>
            @elseif (! $business->hasFriendRewardText())
                {{-- The rewards cause is named first (Section
                     2.4's order), in any allowance state: the
                     turn-on claim belongs to the reward leg
                     here, never to the card. --}}
                <p class="mt-2 font-semibold text-text-1">Your code is not on yet</p>
                <p class="type-body-sm mt-1 text-text-2">Your code turns on once your friend reward is also set.</p>
                @if ($business->allowanceLeft() > 0)
                    <p class="type-body-sm mt-1 text-text-2">You have {{ $business->allowanceLeft() }} of your {{ $freeTotal }} free visits left. Nothing is charged when you add your card.</p>
                @else
                    <p class="type-body-sm mt-1 text-text-2">Your {{ $freeTotal }} free visits are used up. Nothing is charged when you add your card.</p>
                @endif
            @elseif ($business->allowanceLeft() > 0)
                <p class="mt-2 font-semibold text-text-1">Your code is on</p>
                <p class="type-body-sm mt-1 text-text-2">You have {{ $business->allowanceLeft() }} of your {{ $freeTotal }} free visits left. After those, your code pauses until a card is on file. Nothing is charged when you add your card.</p>
            @else
                <p class="mt-2 font-semibold text-text-1">Your code is paused</p>
                <p class="type-body-sm mt-1 text-text-2">Your 5 free visits are used up. Add a card to turn your code back on. Nothing is charged when you add your card.</p>
            @endif
            <div id="card-form" data-billing-card-form class="mt-4"></div>
            @include('owner.settings.partials.billing-card-form', [
                'action' => route('settings.billing.card.store'),
                'mountId' => 'card-form',
                'submitLabel' => 'Add card',
            ])
        @endif
    </section>

    {{-- Block D: this month (BILL-14). Every figure comes from
         VisitValuation::forMonth, the dashboard's own source,
         plus the settlement preview for the accrued line, so
         this page and the Overview can never disagree. --}}
    <section class="panel">
        <h2 class="type-title-3 text-text-1">This month</h2>
        {{-- R2 BILL: the forecast is the headline; the facts
             are tiles. The duplicated money lines (the list and
             the built-up sentence) are removed: the forecast
             line states the accrued figure once. Merge ruling
             (lanes 3 x 6, 2026-10-26): the forecast preview is
             the ALL-months figure, so the one line quotes the
             full amount that will be charged across the separate
             monthly charges (FIX-1); earlier-month arrears are
             named on their own line below the cap bar. --}}
        @include('partials.billing-forecast', ['preview' => $forecastPreview, 'class' => 'type-title-3 mt-2 text-text-1'])
        <div class="mt-4 grid grid-cols-3 gap-3">
            <div class="rounded-md bg-surface-1 px-3 py-2">
                <p class="tile-label">Visits counted</p>
                <p class="tile-value-sm mt-1">{{ $month['verified_visits'] }}</p>
            </div>
            <div class="rounded-md bg-surface-1 px-3 py-2">
                <p class="tile-label">Visit fees so far</p>
                <p class="tile-value-sm mt-1">{{ '$'.number_format($month['fees_cents'] / 100, 2) }}</p>
            </div>
            <div class="rounded-md bg-surface-1 px-3 py-2">
                <p class="tile-label">Free visits left</p>
                {{-- C-21: the tile reads allowanceLeft(), the
                     gate's own count, so the tile, the panel,
                     and the gate print one number. --}}
                <p class="tile-value-sm mt-1">{{ $business->allowanceLeft() }} of {{ $freeTotal }}</p>
            </div>
        </div>
        @php($capCents = (int) $plan->monthly_cap_cents)
        <div class="mt-4 h-2 overflow-hidden rounded-full bg-surface-2" role="progressbar" aria-label="Monthly cap progress" aria-valuemin="0" aria-valuemax="{{ $capCents }}" aria-valuenow="{{ $month['fees_cents'] }}">
            <div class="h-full bg-brand-500" style="width: {{ $capCents > 0 ? min(100, (int) round($month['fees_cents'] / $capCents * 100)) : 0 }}%"></div>
        </div>
        <p class="type-body-sm mt-2 text-text-2">{{ '$'.number_format($month['fees_cents'] / 100, 2) }} of {{ '$'.number_format($capCents / 100, 0) }} monthly cap</p>
        @if (($monthTextCents ?? 0) > 0)
            {{-- FLOW-STATE TRUTH train (B-F4): accrued text
                 charges render here, in the same words and the
                 same month scope as the dashboard Value panel
                 (VAL-02), from the same textFigures figure. A
                 sub-$1 accrual gated the forecast line to null,
                 which left the carry-forward sentence below
                 referencing an amount this page never printed. --}}
            <p class="type-body-sm mt-2 text-text-2">Text charges so far this month: {{ '$'.number_format($monthTextCents / 100, 2) }}</p>
        @endif
        @if (($accruedAll['accrued_cents'] ?? 0) > $accrued['accrued_cents'])
            <p class="mt-3 text-text-1">Earlier months still to charge: {{ '$'.number_format(($accruedAll['accrued_cents'] - $accrued['accrued_cents']) / 100, 2) }}. Each month is charged on its own.</p>
        @endif
        @if (($accrued['accrued_cents'] + ($monthTextCents ?? 0)) > 0 && ($accrued['accrued_cents'] + ($monthTextCents ?? 0)) < $minimumChargeCents)
            <p class="type-body-sm mt-1 text-text-2">Your amount so far is under {{ '$'.number_format($minimumChargeCents / 100, 0) }}, so it carries forward. It joins your next monthly charge.</p>
        @endif
    </section>

    {{-- Block E: charge history (BILL-15), newest first, from
         billing_records, with each record's refunds listed
         under it. Superseded bookkeeping never renders. --}}
    <section class="panel">
        <h2 class="type-title-3 text-text-1">Charges</h2>
        @forelse ($records as $record)
            @php($stateLabel = match ($record->state) {
                'succeeded' => 'Paid',
                'pending' => 'Processing',
                'failed' => 'Did not go through',
                'refunded' => 'Refunded',
                'partially_refunded' => 'Partly refunded',
                'disputed' => 'Disputed',
                'simulated' => 'Record only. No charge was made.',
                default => $record->state,
            })
            <article class="{{ $loop->first ? 'mt-2' : 'mt-3 border-t border-line pt-3' }}">
                @php($statePill = match ($record->state) {
                    'succeeded' => 'pill-success',
                    'pending' => 'pill-info',
                    'failed' => 'pill-error',
                    'refunded' => 'pill-neutral',
                    'partially_refunded', 'disputed' => 'pill-warning',
                    default => null,
                })
                <p class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold text-text-1">{{ '$'.number_format($record->amount_cents / 100, 2) }}</span>
                    @if ($statePill !== null)
                        <span class="pill {{ $statePill }}">{{ $stateLabel }}</span>
                    @else
                        <span class="font-semibold text-text-1">{{ $stateLabel }}</span>
                    @endif
                </p>
                {{-- Merge ruling (lanes 3 x 7): the date line keeps
                     Train 3.5's business-local date and statement
                     window inside the R2 pill row. --}}
                <p class="type-body-sm text-text-2">{{ $business->localTime($record->created_at)->format('M j, Y') }} · {{ ($recordWindows[$record->id] ?? null) !== null ? $recordWindows[$record->id]['start']->format('M j') : $record->period_start->format('M j') }} to {{ ($recordWindows[$record->id] ?? null) !== null ? $recordWindows[$record->id]['end']->format('M j, Y') : $record->period_end->format('M j, Y') }}</p>
                @if ($record->paymentMethod !== null)
                    <p class="type-body-sm text-text-2">{{ $record->paymentMethod->card_brand }} ending {{ $record->paymentMethod->last4 }}</p>
                @endif
                @foreach ($record->refunds as $refund)
                    @php($refundSuffix = match ($refund->state) {
                        'pending' => ' · Processing',
                        'failed' => ' · Did not go through',
                        default => '',
                    })
                    <p class="type-body-sm text-text-2">Refund: {{ '$'.number_format($refund->amount_cents / 100, 2) }}{{ $refundSuffix }}</p>
                @endforeach
                @if ($record->state === 'succeeded')
                    <p class="type-body-sm mt-1"><a class="font-semibold text-link hover:underline" href="{{ route('settings.billing.statement', $record) }}">View statement</a></p>
                @endif
            </article>
        @empty
            <p class="mt-2 text-text-2">No charges yet. Your first charge comes after your {{ $freeTotal }} free visits.</p>
        @endforelse
    </section>
        </div>
    </div>
@endsection
