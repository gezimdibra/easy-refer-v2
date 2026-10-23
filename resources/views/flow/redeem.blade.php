@extends('layouts.flow')

@section('title', 'Your reward | Easy Refer')
@section('flow_label', 'Redemption')
@if ($state === 'redeemed')
    @section('main_class', 'mt-3')
@endif

@section('content')
    {{-- R7, the friend's redemption page (spec Section 3 Step 9,
         inventory REF-RDM): the full state in every variant: the
         show-this screen, the redeemed receipt (the Section 3.5
         flood, R8b's rule that the customer's own screen shows the
         same receipt state when next viewed), the expired page,
         the held page. --}}
    @if ($state === 'show')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <p class="text-sm text-text-2">{{ $business->street }}, {{ $business->city }}</p>
        <h1 class="mt-1 text-[28px] font-bold leading-tight">Show this at {{ $business->name }}</h1>

        <p class="mt-2 text-text-2">Show this code to a staff member. They scan it with their own phone.</p>

        @if ($business->friend_reward_text)
            <p class="mt-2 text-text-2">Your reward: {{ $business->friend_reward_text }}.</p>
        @endif

        <div class="mt-5 text-center">
            <img src="{{ $qrUrl }}" alt="Your redemption QR code" width="220" height="220" class="mx-auto">
        </div>

        <p class="mt-4 text-center text-text-2">Good until {{ $referral->expires_at->format('F j, Y') }}.</p>
    @elseif ($state === 'redeemed')
        @include('partials.success-flood', [
            'rewardText' => $business->friend_reward_text ?? 'Reward redeemed',
            'businessName' => $business->name,
            'confirmedAt' => $referral->redeemed_at,
            'note' => 'Keep this screen as your receipt.',
        ])

        @if ($business->google_review_url)
            <a class="btn btn-secondary w-full mt-5" href="{{ $business->google_review_url }}" rel="noopener">Leave {{ $business->name }} a Google review</a>
        @endif
    @elseif ($state === 'expired')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 text-[28px] font-bold leading-tight">This reward expired on {{ $referral->expires_at->format('F j, Y') }}</h1>
        <p class="mt-2 text-text-2">Rewards are good for {{ $business->redemption_window_days }} days.</p>

        @if ($backUrl ?? null)
            <p class="mt-4"><a class="font-semibold text-brand-700 hover:underline" href="{{ $backUrl }}">Back to {{ $business->name }}</a></p>
        @endif
    @elseif ($state === 'held')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 text-[28px] font-bold leading-tight">Held for review</h1>
        <p class="mt-2 text-text-2">Your reward is being confirmed by {{ $business->name }}. This can take a day.</p>
    @else
        <h1 class="text-[28px] font-bold leading-tight">This code is not active</h1>
        <p class="mt-2 text-text-2">Please ask the business for help, or check that you scanned the whole code.</p>
    @endif
@endsection
