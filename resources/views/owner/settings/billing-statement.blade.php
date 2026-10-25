@extends('layouts.app')

@section('title', 'Statement')

@section('notices_summary_only', '1')

@section('content')
    <h1 class="type-title-2 text-text-1">Statement</h1>

    <nav aria-label="Breadcrumb" class="type-caption mt-2 text-text-2">
        <a class="text-text-2 hover:text-text-1" href="{{ route('settings') }}">Settings</a>
        <span aria-hidden="true"> / </span>
        <a class="text-text-2 hover:text-text-1" href="{{ route('settings.billing') }}">Billing</a>
        <span aria-hidden="true"> / </span>
        <span aria-current="page" class="text-text-1">Statement</span>
    </nav>

    <div class="mt-6 lg:flex lg:items-start lg:gap-8">
        <div class="lg:w-[220px] lg:flex-none">
            @include('owner.settings.nav')
        </div>
        <div class="mt-6 min-w-0 flex-1 lg:mt-0">

    {{-- The per-charge statement (billing UI spec Section 2,
         Block F; BILL-16 labels). Counts are the record's own
         coverage ledger; the charge is exact; the value line
         is the period estimate and is labeled as one. --}}
    <section class="panel">
        <h2 class="type-title-3 text-text-1">{{ $record->period_start->format('M j') }} to {{ $record->period_end->format('M j, Y') }}</h2>
        <p class="mt-2 font-semibold text-text-1">Charge: {{ '$'.number_format($record->amount_cents / 100, 2) }}</p>
        <ul class="mt-3 space-y-1 text-text-1">
            <li>Visits charged: {{ $charged }}</li>
            <li>Free visits used: {{ $freeUsed }}</li>
            <li>Visits past the monthly cap, recorded free: {{ $overCap }}</li>
            @if (($textCampaignsCents ?? 0) > 0)
                <li>Text campaigns: {{ '$'.number_format($textCampaignsCents / 100, 2) }}</li>
            @endif
        </ul>
        <p class="mt-3 text-text-1">What those visits were worth (estimate): {{ '$'.number_format($estimatedSalesCents / 100, 2) }}</p>
        <p class="type-body-sm mt-4 text-text-2">Amounts are in US dollars. Charges are handled by Square.</p>
    </section>
        </div>
    </div>
@endsection
