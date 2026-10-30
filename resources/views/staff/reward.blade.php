@extends('layouts.flow')

@section('title', 'Confirm reward | Easy Refer')
@section('flow_label', 'Counter')

@section('content')
    {{-- R8b, the staff claim confirmation (spec Section 6.1 item
         3, design 3.5). R4-U3 (Surface 4 pick C): the same grammar
         as the redemption confirm: verdict band, the exact reward
         text as the headline, whose it is at counter size, and the
         two verification facts (business, sender) as labelled
         rows in one well. --}}
    <x-state-band state="ready_to_claim" />

    <h1 class="type-display-xs">{{ $reward->reward_text_snapshot }}</h1>

    <p class="mt-3 font-medium text-text-1" style="font-size: 22px; line-height: 28px">For {{ $customer->first_name }}</p>

    <div class="well mt-4">
        <p class="flex items-baseline justify-between gap-3">
            <span class="type-caption text-text-2">Business</span>
            <span class="font-medium text-text-1">{{ $business->name }}</span>
        </p>
        <p class="mt-2 flex items-baseline justify-between gap-3">
            <span class="type-caption text-text-2">Sent by</span>
            <span class="font-medium text-text-1">{{ $reward->referral?->referrerMembership?->customer?->first_name ?? 'Referrer' }}</span>
        </p>
    </div>

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
