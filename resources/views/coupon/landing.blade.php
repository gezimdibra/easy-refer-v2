@extends('layouts.flow')

@section('title', $business->name.' | Easy Refer')
@section('flow_label', 'Coupon')

@section('step_number', '1')
@section('step_total', '2')
@section('step')
    <span class="type-caption text-text-2">Step 1 of 2</span>
@endsection

@section('content')
    {{-- The coupon landing (ID-11, Amendment 53, COUP-21): the
         offer in the batch's own words, the shop, one line on
         how it works, and the phone form. No second person is
         named anywhere on this path: there is no one else. --}}
    <div class="flex items-center gap-3">
        <span class="flex h-11 w-11 flex-none items-center justify-center rounded-lg bg-brand-50 text-text-1" aria-hidden="true"><span style="font-size: 18px; font-weight: 650">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($business->name, 0, 1)) }}</span></span>
        <span>
            <span class="type-title-4 block text-text-1">{{ $business->name }}</span>
            <span class="type-caption block text-text-2">{{ $business->street }}, {{ $business->city }}</span>
        </span>
    </div>

    <h1 class="type-display-xs mt-5">{{ $batch->offer_text }}, at {{ $business->name }}</h1>
    <p class="type-body mt-2 text-text-2">This coupon was placed by {{ $business->name }} for new customers. Claim it now and use it when you come in.</p>
    <p class="type-body mt-2 text-text-2">Show this at the counter after you verify your phone. Good until {{ $business->localTime($batch->expires_at)->format('F j, Y') }}.</p>

    @include('partials.notices', ['summaryOnly' => true, 'summaryList' => true])

    <form method="POST" action="{{ route('coupon.start', ['token' => $token]) }}" class="mt-5" id="coupon-form" data-loading>
        @csrf
        <input type="hidden" name="device_signal" data-device-signal>

        <div class="field">
            <label class="form-label" for="first_name">First name</label>
            <input class="form-input form-input-flow" id="first_name" name="first_name" type="text" autocomplete="given-name" required value="{{ old('first_name') }}">
            @error('first_name') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="form-label" for="last_name">Last name</label>
            <input class="form-input form-input-flow" id="last_name" name="last_name" type="text" autocomplete="family-name" required value="{{ old('last_name') }}">
            @error('last_name') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="form-label" for="phone">Mobile phone</label>
            <input class="form-input form-input-flow" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required value="{{ old('phone') }}">
            @error('phone') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        <label class="well mt-5 flex items-start gap-3 text-base">
            <input type="checkbox" id="consent" name="consent" value="1" class="mt-0.5 h-[22px] w-[22px] flex-none accent-action" @checked(old('consent'))>
            <span>I agree to receive text messages about my coupon from {{ $business->name }}. Message and data rates may apply. Reply STOP to opt out.</span>
        </label>
        @error('consent') <p class="form-error mt-2">{{ $message }}</p> @enderror

        @error('turnstile') <p class="form-error mt-3">{{ $message }}</p> @enderror
        @include('partials.turnstile')
    </form>
@endsection

@section('action')
    <button type="submit" form="coupon-form" class="btn btn-flow btn-primary w-full">Claim this coupon</button>
@endsection
