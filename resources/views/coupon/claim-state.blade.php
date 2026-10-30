@extends('layouts.flow')

@section('title', 'Your coupon | Easy Refer')
@section('flow_label', 'Coupon')

@section('content')
    {{-- The reopened claim's terminal truths (ID-11,
         Amendment 53, COUP-28): redeemed, expired, or
         cancelled, each in its own words, with its date. --}}
    <p class="text-sm text-text-2">{{ $business->name }}</p>
    @if ($claim->state === 'redeemed')
        <h1 class="type-display-xs mt-3">Redeemed. Enjoy.</h1>
        <p class="type-body mt-2 text-text-2">{{ $claim->offer_text }} at {{ $business->name }}, redeemed on {{ $business->localTime($claim->redeemed_at)->format('F j, Y') }}.</p>
    @elseif ($claim->state === 'voided')
        <h1 class="type-display-xs mt-3">This coupon was cancelled by {{ $business->name }}.</h1>
        <p class="type-body mt-2 text-text-2">Cancelled on {{ $business->localTime($claim->updated_at)->format('F j, Y') }}.</p>
    @else
        <h1 class="type-display-xs mt-3">This coupon expired on {{ $business->localTime($claim->expires_at)->format('F j, Y') }}.</h1>
    @endif
@endsection
