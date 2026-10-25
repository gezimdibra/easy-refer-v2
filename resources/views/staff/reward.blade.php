@extends('layouts.flow')

@section('title', 'Confirm reward | Easy Refer')
@section('flow_label', 'Counter')

@section('content')
    {{-- R8b, the staff claim confirmation (spec Section 6.1 item
         3, design 3.5): the business eyebrow, the exact reward
         text as the headline, whose it is, the kind line naming
         the referrer whose visit earned it, and one action. --}}
    <p class="type-eyebrow text-text-3">{{ $business->name }}</p>
    <h1 class="mt-1 type-display-xs">{{ $reward->reward_text_snapshot }}</h1>

    <p class="mt-2 text-lg">For {{ $customer->first_name }}</p>
    <p class="mt-1 text-text-2">{{ $reward->referral?->referrerMembership?->customer?->first_name ?? 'Referrer' }}'s reward</p>

    @if (session('staff_error'))
        <p class="status status-error mt-3" role="status">{{ session('staff_error') }}</p>
    @endif
@endsection

@section('action')
    <form method="POST" action="{{ route('redeem.confirm', ['token' => $token]) }}">
        @csrf
        <input type="hidden" name="nonce" value="{{ $nonce }}">
        <button type="submit" class="btn btn-counter btn-primary w-full">Mark as Claimed</button>
    </form>
    <p class="mt-3 text-center">
        <a class="font-semibold text-link hover:underline" href="{{ route('home') }}">Not now</a>
    </p>
@endsection
