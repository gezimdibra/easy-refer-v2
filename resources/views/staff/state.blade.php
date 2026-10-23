@extends('layouts.flow')

@section('title', 'Easy Refer')
@section('flow_label', 'Counter')

@section('content')
    {{-- The staff state screens (spec Section 6.1 item 6, 6.2):
         the canonical R0 answer for unknown, malformed, and
         terminal tokens (identical information in every case, a
         real 404), and the honest resume states for tokens that
         already completed. Every sentence is audience-specific:
         staff copy never borrows the customer's wording. --}}
    @if ($state === 'generic')
        <h1 class="text-[28px] font-bold leading-tight">This code is not active</h1>
        <p class="mt-2 text-text-2">Please ask the business for help, or check that you scanned the whole code.</p>
    @elseif ($state === 'already_redeemed')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 text-[28px] font-bold leading-tight">Already redeemed</h1>
        <p class="mt-2 text-text-2">{{ $friendName }}'s reward was redeemed on {{ $date->format('F j, Y') }}. Nothing more to do.</p>
    @elseif ($state === 'already_claimed')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 text-[28px] font-bold leading-tight">Already claimed</h1>
        <p class="mt-2 text-text-2">{{ $friendName }}'s reward was claimed on {{ $date->format('F j, Y') }}. Nothing more to do.</p>
    @elseif ($state === 'expired')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 text-[28px] font-bold leading-tight">This reward expired on {{ $date->format('F j, Y') }}.</h1>
    @elseif ($state === 'held')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 text-[28px] font-bold leading-tight">This one needs the owner's review first</h1>
        <p class="mt-2 text-text-2">The owner reviews these in the dashboard.</p>
    @elseif ($state === 'not_yet')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 text-[28px] font-bold leading-tight">{{ $rewardText }}</h1>
        <p class="mt-2 text-text-2">Available on {{ $date->format('F j, Y') }}.</p>
    @endif
@endsection
