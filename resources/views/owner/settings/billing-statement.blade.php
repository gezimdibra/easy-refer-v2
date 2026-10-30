@extends('layouts.app')

@section('title', 'Statement')


@section('content')
    {{-- BS-1 (gap pack 04, Variant A; Copy Amendment 29 [BS-1]):
         the H1 names the record's own period, rendered from its
         claimed rows exactly as the statement window has always
         rendered it, and the Paid pill states the fact the
         controller already guarantees (only a succeeded record
         has a statement). --}}
    <div class="flex flex-wrap items-center gap-3">
        <h1 class="type-title-2 text-text-1">Statement for {{ $statementWindow['start']->format('M j') }} to {{ $statementWindow['end']->format('M j, Y') }}</h1>
        <span class="pill pill-success">Paid</span>
    </div>
    {{-- Train 3.5: the window is named in business-local terms
         (StatementWindow over the claimed rows' own instants)
         and labeled when it spans parts of two calendar months;
         the window itself is unchanged. --}}
    @if ($statementWindow['carry_line'] !== null)
        <p class="type-body-sm mt-2 text-text-2">{{ $statementWindow['carry_line'] }}</p>
    @endif

    <div class="mt-6 lg:flex lg:items-start lg:gap-8">
        <div class="lg:w-[220px] lg:flex-none">
            @include('owner.settings.nav')
        </div>
        <div class="mt-6 min-w-0 flex-1 lg:mt-0">

    {{-- The per-charge statement (billing UI spec Section 2,
         Block F; BILL-16 labels), as a receipt (BS-2): counts
         are the record's own coverage ledger, each covered row
         shows $0.00 explicitly, the texts row carries the
         claimed recipient cents, and the total row repeats the
         record amount so the rows visibly sum to it. The charge
         is exact; the value line is the period estimate, labeled
         as one, in its own panel below (BS-4). --}}
    <p class="type-body-sm text-text-2">Charge</p>
    <p class="tile-value mt-1">{{ '$'.number_format($record->amount_cents / 100, 2) }}</p>

    <section class="panel mt-6">
        <div class="rounded-md bg-surface-1 px-4 py-3">
            <dl class="space-y-3 text-text-1">
                @if ($campaignOnly)
                    {{-- BS-3: a campaign-only record leads with
                         the texts row; the visit rows collapse
                         to the single ratified line below, so no
                         zero-count visit line ever headlines a
                         real charge. --}}
                    <div class="flex items-baseline justify-between gap-3">
                        <div>
                            <dt>Text campaigns:</dt>
                            <p class="type-body-sm mt-1 text-text-2">Texts sent in campaigns this charge covers, at 2 cents per text segment for each customer.</p>
                        </div>
                        <dd>{{ '$'.number_format($textCampaignsCents / 100, 2) }}</dd>
                    </div>
                    <p class="text-text-1">No referral visits in this charge.</p>
                @else
                    <div class="flex items-baseline justify-between gap-3">
                        <dt>Visits charged: {{ $charged }}</dt>
                        <dd>{{ '$'.number_format($chargedCents / 100, 2) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <div>
                            <dt>Free visits used: {{ $freeUsed }}</dt>
                            <p class="type-body-sm mt-1 text-text-2">Covered by the free visits included with your program.</p>
                        </div>
                        <dd>$0.00</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <div>
                            <dt>Visits past the monthly cap, recorded free: {{ $overCap }}</dt>
                            <p class="type-body-sm mt-1 text-text-2">Recorded, not charged. Your monthly cap was already reached.</p>
                        </div>
                        <dd>$0.00</dd>
                    </div>
                    @if (($textCampaignsCents ?? 0) > 0)
                        <div class="flex items-baseline justify-between gap-3">
                            <div>
                                <dt>Text campaigns:</dt>
                                <p class="type-body-sm mt-1 text-text-2">Texts sent in campaigns this charge covers, at 2 cents per text segment for each customer.</p>
                            </div>
                            <dd>{{ '$'.number_format($textCampaignsCents / 100, 2) }}</dd>
                        </div>
                    @endif
                @endif
                <div class="flex items-baseline justify-between gap-3 border-t border-line pt-3">
                    <dt class="font-semibold">Total</dt>
                    <dd class="tile-value-sm">{{ '$'.number_format($record->amount_cents / 100, 2) }}</dd>
                </div>
            </dl>
        </div>
        <p class="type-body-sm mt-4 text-text-2">Amounts are in US dollars. Charges are handled by Square.</p>
        <p class="type-body-sm mt-2 text-text-2">Questions about this charge? See Billing for your card and plan.</p>
    </section>

    {{-- BS-4: the estimate is quarantined in its own panel
         below the receipt; exact and estimated money never
         share a panel. P1 SPEND-DEFAULT: the worth figure is
         computed from the owner's typical spend per visit, so
         the panel renders only when the owner has set one; an
         unset business is never shown a $0.00 worth dressed
         as an estimate. --}}
    @if ($business->hasTypicalSpend())
        <section class="panel mt-6">
            <p class="text-text-1">What those visits were worth (estimate): {{ '$'.number_format($estimatedSalesCents / 100, 2) }}</p>
        </section>
    @endif
        </div>
    </div>
@endsection
