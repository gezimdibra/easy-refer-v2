@extends('layouts.app')

@section('title', 'Billing')

@section('notices_summary_only', '1')

@section('content')
    <h1 class="type-title-2 text-text-1">Billing</h1>

    <nav aria-label="Breadcrumb" class="type-caption mt-2 text-text-2">
        <a class="text-text-2 hover:text-text-1" href="{{ route('settings') }}">Settings</a>
        <span aria-hidden="true"> / </span>
        <span aria-current="page" class="text-text-1">Billing</span>
    </nav>

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
        <section class="panel" aria-label="Billing status">
            @include('partials.billing-past-due', ['cardHref' => '#card'])
        </section>
    @endif

    @if ($business->billing_status === 'active' && $card === null)
        <section class="panel" aria-label="Billing status">
            <p class="text-text-1">No card on file. Visits keep counting, and the amount builds up here. Add a card to restart monthly charges.</p>
        </section>
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
        <p class="mt-1 text-text-2">{{ '$'.number_format($plan->perVisitPriceCents() / 100, 2) }} per visit. Never more than {{ '$'.number_format($plan->monthly_cap_cents / 100, 0) }} a month.</p>
        <p class="type-body-sm mt-3 text-text-2">To stop billing entirely, close your account.</p>
    </section>

    {{-- Block C: card on file (DASH-14). The capture form
         itself lands in BU-4 (Square Web Payments SDK behind
         the step-up for replace and remove); the mount point
         below is its home. A pending row renders the BILL-05
         confirming state, never as a card. --}}
    <section class="panel" id="card">
        <h2 class="type-title-3 text-text-1">Card on file</h2>
        @if ($card !== null)
            <p class="mt-2 text-text-1">Card on file ending {{ $card->last4 }}. Managed securely by Square.</p>
            <p class="type-body-sm mt-1 text-text-2">{{ $card->card_brand }} · Expires {{ str_pad((string) $card->exp_month, 2, '0', STR_PAD_LEFT) }}/{{ $card->exp_year }}@if ($cardExpiresSoon) <span class="pill pill-warning ml-2">Expires soon</span>@endif</p>
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
            @if ($business->free_visits_remaining >= $freeTotal)
                <p class="mt-2 font-semibold text-text-1">Add a card to turn on your QR code</p>
                <p class="type-body-sm mt-1 text-text-2">Your first {{ $freeTotal }} visits are free. Nothing is charged when you add your card.</p>
            @else
                <p class="mt-2 font-semibold text-text-1">Add a card to restart monthly charges</p>
                @if ($business->free_visits_remaining > 0)
                    <p class="type-body-sm mt-1 text-text-2">You have {{ $business->free_visits_remaining }} of your {{ $freeTotal }} free visits left. Nothing is charged when you add your card.</p>
                @else
                    <p class="type-body-sm mt-1 text-text-2">Your {{ $freeTotal }} free visits are used up. Nothing is charged when you add your card.</p>
                @endif
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
        <ul class="mt-2 space-y-1 text-text-1">
            <li>{{ $month['verified_visits'] }} {{ $month['verified_visits'] === 1 ? 'visit' : 'visits' }} counted this month</li>
            <li>Free visits left: {{ $business->free_visits_remaining }} of {{ $freeTotal }}</li>
            <li>Fees so far this month: {{ '$'.number_format($month['fees_cents'] / 100, 2) }}</li>
        </ul>
        @php($capCents = (int) $plan->monthly_cap_cents)
        <div class="mt-4 h-2 overflow-hidden rounded-full bg-surface-2" role="progressbar" aria-label="Monthly cap progress" aria-valuemin="0" aria-valuemax="{{ $capCents }}" aria-valuenow="{{ $month['fees_cents'] }}">
            <div class="h-full bg-brand-500" style="width: {{ $capCents > 0 ? min(100, (int) round($month['fees_cents'] / $capCents * 100)) : 0 }}%"></div>
        </div>
        <p class="type-body-sm mt-2 text-text-2">{{ '$'.number_format($month['fees_cents'] / 100, 2) }} of {{ '$'.number_format($capCents / 100, 0) }} monthly cap</p>
        <p class="mt-3 text-text-1">Built up so far: {{ '$'.number_format($accrued['accrued_cents'] / 100, 2) }}. Charged once a month.</p>
        @include('partials.billing-forecast', ['preview' => $accrued])
        @if ($accrued['accrued_cents'] > 0 && $accrued['accrued_cents'] < $minimumChargeCents)
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
                <p class="font-semibold text-text-1">{{ '$'.number_format($record->amount_cents / 100, 2) }} · {{ $stateLabel }}</p>
                <p class="type-body-sm text-text-2">{{ $record->created_at->format('M j, Y') }} · {{ $record->period_start->format('M j') }} to {{ $record->period_end->format('M j, Y') }}</p>
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
