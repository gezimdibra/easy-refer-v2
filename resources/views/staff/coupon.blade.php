@extends('layouts.flow')

@section('title', 'Confirm redemption | Easy Refer')
@section('flow_label', 'Counter')

@section('content')
    {{-- The staff coupon screen (ID-11, Amendment 53,
         COUP-26): the shipped counter screen with exactly one
         delta, the source line naming the batch, so staff can
         place the paper ("the gym flyers"). The button, the
         nonce, and the act are the shipped ones. --}}
    <x-state-band state="ready_to_redeem" />

    <p class="type-body mt-3 font-semibold text-text-1">Coupon: {{ $batch->label }}</p>

    <h1 class="type-display-xs mt-2">{{ $claim->offer_text }}</h1>

    <div class="well mt-4">
        <p class="flex items-baseline justify-between gap-3">
            <span class="type-caption text-text-2">Business</span>
            <span class="font-medium text-text-1">{{ $business->name }}</span>
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
        <button type="submit" class="btn btn-counter btn-primary w-full">Mark as Redeemed</button>
    </form>
    <p class="mt-3 text-center">
        <a class="font-semibold text-link hover:underline" href="{{ route('home') }}">Not now</a>
    </p>
@endsection
