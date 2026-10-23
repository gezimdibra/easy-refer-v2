@extends('layouts.flow')

@section('title', $business->name.' | Easy Refer')
@section('flow_label', 'Join and refer')

@section('step')
    <span class="text-sm text-text-2">Step 1 of 3</span>
@endsection

@section('content')
    {{-- R1/R2, the QR landing (spec Section 3 Step 2, design
         Section 3.2 R1): business name, the friend welcome reward
         as the reward headline ("Get {reward} on your first
         visit"), one sentence of how it works, and the enrollment
         form on the same screen. Errors follow the R1 pattern: one
         summary line above the form, each problem stated once at
         its field (Section 4.4 rule 6). --}}
    <p class="text-sm text-text-2">{{ $business->name }}</p>
    <p class="text-sm text-text-2">{{ $business->street }}, {{ $business->city }}</p>

    @if ($business->friend_reward_text)
        <h1 class="mt-1 text-[28px] font-bold leading-tight">Get {{ $business->friend_reward_text }} on your first visit</h1>
    @else
        <h1 class="mt-1 text-[28px] font-bold leading-tight">Join {{ $business->name }}'s referral program</h1>
    @endif
    <p class="mt-2 text-text-2">Enter your phone, get your own link, share it with a friend</p>

    @include('partials.notices', ['summaryOnly' => true])

    <form method="POST" action="{{ route('refer.enroll', ['token' => $token]) }}" class="mt-5" id="enroll-form">
        @csrf
        <input type="hidden" name="device_signal" data-device-signal>

        <div class="field">
            <label class="form-label" for="first_name">First name</label>
            <input class="form-input form-input-flow" id="first_name" name="first_name" type="text" autocomplete="given-name" required value="{{ old('first_name') }}"
                aria-invalid="{{ $errors->has('first_name') ? 'true' : 'false' }}"
                @if ($errors->has('first_name')) aria-describedby="first_name-error" @endif>
            @error('first_name') <p class="form-error" id="first_name-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="form-label" for="last_name">Last name</label>
            <input class="form-input form-input-flow" id="last_name" name="last_name" type="text" autocomplete="family-name" required value="{{ old('last_name') }}"
                aria-invalid="{{ $errors->has('last_name') ? 'true' : 'false' }}"
                @if ($errors->has('last_name')) aria-describedby="last_name-error" @endif>
            @error('last_name') <p class="form-error" id="last_name-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="form-label" for="phone">Mobile phone</label>
            <input class="form-input form-input-flow" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required value="{{ old('phone') }}"
                aria-invalid="{{ $errors->has('phone') ? 'true' : 'false' }}"
                @if ($phoneHelp ?? null) aria-describedby="phone-help @error('phone') phone-error @enderror" @elseif ($errors->has('phone')) aria-describedby="phone-error" @endif>
            @if ($phoneHelp ?? null)
                <p class="form-help" id="phone-help">{{ $phoneHelp }}</p>
            @endif
            @error('phone') <p class="form-error" id="phone-error">{{ $message }}</p> @enderror
        </div>

        @if (config('referrals.customer_email_field'))
            <div class="field">
                <label class="form-label" for="email">Email (optional)</label>
                <input class="form-input form-input-flow" id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}"
                    aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                    @if ($errors->has('email')) aria-describedby="email-error" @endif>
                @error('email') <p class="form-error" id="email-error">{{ $message }}</p> @enderror
            </div>
        @endif

        <label class="mt-4 flex items-start gap-3 text-base">
            <input type="checkbox" name="consent" value="1" class="mt-1 h-5 w-5" @checked(old('consent'))>
            <span>I agree to receive text messages about my rewards from {{ $business->name }}. Message and data rates may apply. Reply STOP to opt out.</span>
        </label>

        @error('turnstile') <p class="form-error mt-3">{{ $message }}</p> @enderror
        @include('partials.turnstile')
    </form>
@endsection

@section('action')
    {{-- The sticky bottom action bar (design Sections 2.4, 2.5):
         the screen's primary action lives here on every flow form. --}}
    <button type="submit" form="enroll-form" class="btn btn-flow btn-primary w-full">Continue</button>
@endsection
