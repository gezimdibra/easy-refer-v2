@extends('layouts.app')

@section('title', 'Batch live')

@section('content')
    {{-- [COUP-08] + [COUP-11] (Amendment 53): the done screen.
         Print is the primary action, the last step of creation,
         not a later errand (finding FC-4). --}}
    <h1 class="type-title-2 text-text-1">Your batch is live.</h1>
    <p class="type-body mt-2 text-text-2">{{ $batch->label }}: {{ $batch->offer_text }}. Anyone who scans it can claim once, until {{ $business->localTime($batch->expires_at)->format('F j, Y') }} or until {{ $batch->claim_cap }} people have claimed.</p>

    <p class="mt-4">
        <a class="btn btn-primary" href="{{ route('owner.qr.coupons.flyer', ['batch' => $batch, 'print' => 1]) }}"><x-icon name="printer" :size="20" /> Print the flyer sheet</a>
    </p>

    <p class="type-body mt-4 text-text-2">These go outside, not on your counter. Good first spots: a gym counter, a laundromat board, the shop next door that is not a competitor. One batch per spot, so you can see which spot brings people in.</p>

    <p class="type-body mt-4 text-text-2">Your short link, to share a photo of the flyer anywhere: {{ preg_replace('#^https?://#', '', route('coupon.landing', ['token' => $batch->plainToken()])) }}</p>

    <p class="mt-4">
        <a class="btn btn-secondary" href="{{ route('owner.qr.coupons') }}">Back to Coupons</a>
    </p>
@endsection
