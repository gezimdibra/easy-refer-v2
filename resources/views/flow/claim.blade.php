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
        <div class="flex items-center gap-3">
            <span class="flex h-11 w-11 flex-none items-center justify-center rounded-lg bg-brand-50 text-brand-700" aria-hidden="true"><span style="font-size: 18px; font-weight: 650">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($business->name, 0, 1)) }}</span></span>
            <span>
                <span class="type-title-4 block text-text-1">{{ $business->name }}</span>
                <span class="type-caption block text-text-2">{{ $business->street }}, {{ $business->city }}</span>
            </span>
        </div>
        <h1 class="type-display-xs mt-5">Your reward is ready</h1>
        <p class="mt-2 text-text-2">Your reward: {{ $reward->reward_text_snapshot }}.</p>
        <p class="mt-2 text-text-2">Show this code to a staff member. They scan it with their own phone.</p>

        <div class="well mt-5 text-center">
            <img src="{{ $qrUrl }}" alt="Your reward QR code" width="220" height="220" class="mx-auto rounded-lg border border-line bg-surface-0 p-3">
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
        <h1 class="type-display-xs mt-5">Your reward is on its way</h1>
        <p class="mt-2 text-text-2">Your reward: {{ $reward->reward_text_snapshot }}.</p>

        @if ($showAvailableDate)
            <p class="mt-2 text-text-2">Your reward will be available on {{ $reward->available_at->format('F j, Y') }}.</p>
        @else
            <p class="mt-2 text-text-2">We will text you when it is ready.</p>
        @endif
    @elseif ($state === 'held')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="type-display-xs mt-5">Held for review</h1>
        <p class="mt-2 text-text-2">Your reward is being confirmed by {{ $business->name }}. This can take a day.</p>
    @elseif ($state === 'denied')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <h1 class="type-display-xs mt-5">This reward was not approved by {{ $business->name }}.</h1>
        <p class="mt-2 text-text-2">If you think this is a mistake, contact the store.</p>
    @else
        <h1 class="type-display-xs">This link is not available</h1>
        <p class="mt-2 text-text-2">This link could not be used. Check the message we sent you for your latest link.</p>
    @endif
@endsection
