@extends('layouts.flow')

@section('title', 'Your coupon | Easy Refer')
@section('flow_label', 'Coupon')

@section('step')
    <x-state-pill state="ready" />
@endsection

@section('content')
    {{-- The ready screen (ID-11, Amendment 53, COUP-23): the
         unlocked coupon, immediately usable. One action only:
         show it at the counter. There is deliberately no share
         prompt and no second-person content on this screen. --}}
    <div class="flex items-center gap-3">
        <span class="flex h-11 w-11 flex-none items-center justify-center rounded-lg bg-brand-50 text-text-1" aria-hidden="true"><span style="font-size: 18px; font-weight: 650">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($business->name, 0, 1)) }}</span></span>
        <span>
            <span class="type-title-4 block text-text-1">{{ $business->name }}</span>
            <span class="type-caption block text-text-2">{{ $business->street }}, {{ $business->city }}</span>
        </span>
    </div>

    <h1 class="type-display-xs mt-5">Your coupon is ready.</h1>
    <p class="type-body mt-2 text-text-2">{{ $claim->offer_text }} at {{ $business->name }}. Show this screen's code at the counter. Good until {{ $business->localTime($claim->expires_at)->format('F j, Y') }}.</p>

    <div class="mt-5 text-center">
        <span class="qr-stage"><img src="{{ $qrUrl }}" alt="Your coupon QR code" width="248" height="248"></span>
    </div>

    <p class="mt-4 text-center text-text-2">Can't scan? Go to {{ preg_replace('#^https?://#', '', $claimUrl) }}</p>
@endsection
