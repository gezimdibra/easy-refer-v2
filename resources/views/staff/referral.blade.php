@extends('layouts.flow')

@section('title', 'Confirm redemption | Easy Refer')
@section('flow_label', 'Counter')

@section('content')
    {{-- R8a, the staff redemption confirmation (spec Section 6.1
         item 3, design 3.5): the business eyebrow, the reward as
         the headline, whose it is, the kind line, and one action.
         No PIN, no account: whoever holds the phone confirms. --}}
    <p class="type-eyebrow text-text-3">{{ $business->name }}</p>

    <h1 class="mt-1 type-display-xs">{{ $business->friend_reward_text ?? 'First visit reward' }}</h1>

    <p class="mt-2 text-lg">For {{ $friend->first_name }}</p>
    @if ($business->friend_reward_text)
        <p class="mt-1 text-text-2">First visit reward</p>
    @endif

    @if (session('staff_error'))
        <p class="status status-error mt-3" role="status">{{ session('staff_error') }}</p>
    @endif
@endsection

@section('action')
    <form method="POST" action="{{ route('redeem.confirm', ['token' => $token]) }}">
        @csrf
        <input type="hidden" name="nonce" value="{{ $nonce }}">
        <button type="submit" class="btn btn-counter btn-primary w-full">Mark as Redeemed</button>
    </form>
    <p class="mt-3 text-center">
        <a class="font-semibold text-link hover:underline" href="{{ route('home') }}">Not now</a>
    </p>
@endsection
