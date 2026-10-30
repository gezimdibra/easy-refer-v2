@extends('layouts.flow')

@section('title', $business->name.' | Easy Refer')
@section('flow_label', 'Coupon')

@section('content')
    {{-- The limit page (ID-11, Amendment 53, COUP-24): the
         per-phone rule stated as bookkeeping, never as an
         accusation. A live claim is shown; a spent one is
         named as spent. --}}
    <p class="text-sm text-text-2">{{ $business->name }}</p>
    @if ($claim->state === 'ready')
        <h1 class="type-display-xs mt-3">You already have a coupon for {{ $business->name }}. It is good until {{ $business->localTime($claim->expires_at)->format('F j, Y') }}.</h1>
        @if ($claimUrl)
            <p class="mt-5"><a class="btn btn-flow btn-primary w-full" href="{{ $claimUrl }}">See your coupon</a></p>
        @endif
    @else
        <h1 class="type-display-xs mt-3">You have already claimed a coupon at {{ $business->name }}. This one is one per person.</h1>
    @endif
@endsection
