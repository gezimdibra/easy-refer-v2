@extends('layouts.flow')

@section('title', 'Easy Refer')
@section('flow_label', 'Counter')

@section('content')
    {{-- The staff state screens (spec Section 6.1 item 6, 6.2):
         the canonical R0 answer for unknown, malformed, and
         terminal tokens (identical information in every case, a
         real 404), and the honest resume states for tokens that
         already completed. Every sentence is audience-specific:
         staff copy never borrows the customer's wording.
         R4-U1 (Surface 5): every known state opens with the state
         band in its family and restores the reward text (as the
         headline where the state allows it, as a fact row on
         held, whose headline stays the review state). The generic
         state carries no band: it stays information-identical. --}}
    @if ($state === 'generic')
        <h1 class="type-display-xs">This code is not active</h1>
        <p class="mt-2 text-text-2">Please ask the business for help, or check that you scanned the whole code.</p>
    @elseif ($state === 'already_redeemed')
        <x-state-band state="already_redeemed" :detail="$date->format('M j')" />
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 type-display-xs">Already redeemed</h1>
        {{-- TWO-KEY FLAG (F-UP-1): reward-truth hunk. Renders
             $friendRewardText (the claim-time snapshot, passed
             by the controller), not live config. --}}
        @if ($friendRewardText ?? null)
            <p class="mt-2 text-text-1">{{ $friendRewardText }}</p>
        @endif
        <p class="mt-2 text-text-2">{{ $friendName }}'s reward was redeemed on {{ $date->format('F j, Y') }}. Nothing more to do.</p>
    @elseif ($state === 'already_claimed')
        <x-state-band state="already_claimed" :detail="$date->format('M j')" />
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 type-display-xs">Already claimed</h1>
        <p class="mt-2 text-text-1">{{ $rewardText }}</p>
        <p class="mt-2 text-text-2">{{ $friendName }}'s reward was claimed on {{ $date->format('F j, Y') }}. Nothing more to do.</p>
    @elseif ($state === 'expired')
        <x-state-band state="expired" :detail="$date->format('M j')" />
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 type-display-xs">This reward expired on {{ $date->format('F j, Y') }}.</h1>
        {{-- TWO-KEY FLAG (F-UP-1): reward-truth hunk. Renders
             $friendRewardText (the claim-time snapshot, passed
             by the controller), not live config. --}}
        @if ($friendRewardText ?? null)
            <p class="mt-2 text-text-2">{{ $friendRewardText }}</p>
        @endif
    @elseif ($state === 'coupon_already_redeemed')
        <x-state-band state="already_redeemed" :detail="$date->format('M j')" />
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 type-display-xs">Already redeemed</h1>
        <p class="mt-2 text-text-1">{{ $offerText }}</p>
        <p class="mt-2 text-text-2">This coupon was redeemed on {{ $date->format('F j, Y') }}. Nothing more to do.</p>
    @elseif ($state === 'coupon_expired')
        <x-state-band state="expired" :detail="$date->format('M j')" />
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 type-display-xs">This coupon expired on {{ $date->format('F j, Y') }}.</h1>
        <p class="mt-2 text-text-2">{{ $offerText }}</p>
    @elseif ($state === 'coupon_voided')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 type-display-xs">This coupon was cancelled</h1>
        <p class="mt-2 text-text-2">{{ $offerText }}</p>
        <p class="mt-2 text-text-2">It was cancelled on {{ $date->format('F j, Y') }}. Nothing more to do.</p>
    @elseif ($state === 'held')
        <x-state-band state="owner_review" />
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 type-display-xs">This one needs the owner's review first</h1>
        <p class="mt-2 text-text-2">The owner reviews these in the dashboard.</p>
        @if ($rewardText ?? null)
            <div class="well mt-4">
                <p class="type-caption text-text-2">Reward</p>
                <p class="mt-1 font-medium text-text-1">{{ $rewardText }}</p>
            </div>
        @endif
    @elseif ($state === 'not_yet')
        <x-state-band state="not_yet" :detail="'From '.$date->format('M j')" />
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 type-display-xs">{{ $rewardText }}</h1>
        <p class="mt-2 text-text-2">Available on {{ $date->format('F j, Y') }}.</p>
    @endif
@endsection
