@extends('layouts.flow')

@section('title', 'Your reward | Easy Refer')
@section('flow_label', 'Reward')
@if ($state === 'claimed')
    @section('main_class', 'mt-3')
@endif

@section('content')
    {{-- R9, the referrer's claim page (spec Section 6.1 item 5,
         inventory RDM-CLAIM): every state; the availability date
         only inside the referrer's own live flow session (G-17),
         which the controller decides, never this template. The
         claimed state is the Section 3.5 receipt flood, the same
         receipt the staff phone showed at the counter. --}}
    @if ($state === 'ready')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <p class="text-sm text-text-2">{{ $business->street }}, {{ $business->city }}</p>
        <h1 class="mt-1 text-[28px] font-bold leading-tight">Your reward is ready</h1>
        <p class="mt-2 text-text-2">Your reward: {{ $reward->reward_text_snapshot }}.</p>
        <p class="mt-2 text-text-2">Show this code to a staff member. They scan it with their own phone.</p>

        <div class="mt-5 text-center">
            <img src="{{ $qrUrl }}" alt="Your reward QR code" width="220" height="220" class="mx-auto">
        </div>
    @elseif ($state === 'claimed')
        @include('partials.success-flood', [
            'rewardText' => $reward->reward_text_snapshot,
            'businessName' => $business->name,
            'confirmedAt' => $reward->claimed_at,
            'note' => 'Keep this screen as your receipt.',
        ])

        @if ($business->google_review_url)
            <a class="btn btn-secondary w-full mt-5" href="{{ $business->google_review_url }}" rel="noopener">Leave {{ $business->name }} a Google review</a>
        @endif
    @elseif ($state === 'waiting')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 text-[28px] font-bold leading-tight">Your reward is on its way</h1>
        <p class="mt-2 text-text-2">Your reward: {{ $reward->reward_text_snapshot }}.</p>

        @if ($showAvailableDate)
            <p class="mt-2 text-text-2">Your reward will be available on {{ $reward->available_at->format('F j, Y') }}.</p>
        @else
            <p class="mt-2 text-text-2">We will text you when it is ready.</p>
        @endif
    @elseif ($state === 'held')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 text-[28px] font-bold leading-tight">Held for review</h1>
        <p class="mt-2 text-text-2">Your reward is being confirmed by {{ $business->name }}. This can take a day.</p>
    @elseif ($state === 'denied')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="mt-1 text-[28px] font-bold leading-tight">This reward was not approved by {{ $business->name }}.</h1>
        <p class="mt-2 text-text-2">If you think this is a mistake, contact the store.</p>
    @else
        <h1 class="text-[28px] font-bold leading-tight">This link is not available</h1>
        <p class="mt-2 text-text-2">This link could not be used. Check the message we sent you for your latest link.</p>
    @endif
@endsection
