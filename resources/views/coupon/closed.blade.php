@extends('layouts.flow')

@section('title', $business->name.' | Easy Refer')
@section('flow_label', 'Coupon')

@section('content')
    {{-- The closed-offer page (ID-11, Amendment 53, COUP-25):
         one form per gate state, each naming its own truth in
         public words. No form names an internal reason, a
         billing state, or a next step that does not exist, and
         no phone field renders here: no one verifies into a
         dead end. --}}
    <p class="text-sm text-text-2">{{ $business->name }}</p>
    @if ($form === 'full')
        <h1 class="type-display-xs mt-3">This offer reached its limit of {{ $batch->claim_cap }} coupons.</h1>
    @elseif ($form === 'ended')
        <h1 class="type-display-xs mt-3">This offer from {{ $business->name }} has ended.</h1>
    @else
        <h1 class="type-display-xs mt-3">{{ $business->name }} is not accepting coupons right now.</h1>
    @endif
    <p class="type-body mt-2 text-text-2">Thanks for stopping by.</p>
@endsection
