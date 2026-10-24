@extends('layouts.flow')

@section('title', $business->name.' | Easy Refer')
@section('flow_label', 'Join and refer')

@section('step_number', '1')
@section('step')
    <span class="type-caption text-text-2">Step 1 of 3</span>
@endsection

@section('content')
    {{-- R1/R2, the QR landing (spec Section 3 Step 2, design
         Section 3.2 R1): business name, the friend welcome reward
         as the reward headline, composed by
         Business::friendRewardHeadline() so the first-visit
         phrase can never double up against a reward text that
         already carries it (F-2), one sentence of how it works,
         and the enrollment form on the same screen. Errors follow
         the R1 pattern: one summary line above the form, each
         problem stated once at its field (Section 4.4 rule 6). --}}
    <div class="flex items-center gap-3">
        <span class="flex h-11 w-11 flex-none items-center justify-center rounded-lg bg-brand-50 text-brand-700" aria-hidden="true"><span style="font-size: 18px; font-weight: 650">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($business->name, 0, 1)) }}</span></span>
        <span>
            <span class="type-title-4 block text-text-1">{{ $business->name }}</span>
            <span class="type-caption block text-text-2">{{ $business->street }}, {{ $business->city }}</span>
        </span>
    </div>

    @if ($business->friend_reward_text)
        <h1 class="type-display-xs mt-5">{{ $business->friendRewardHeadline() }}</h1>
    @else
        <h1 class="type-display-xs mt-5">Join {{ $business->name }}'s referral program</h1>
    @endif
    <p class="type-body mt-2 text-text-2">Enter your phone, get your own link, share it with a friend</p>

    @include('partials.notices', ['summaryOnly' => true])

    <form method="POST" action="{{ route('refer.enroll', ['token' => $token]) }}" class="mt-5" id="enroll-form" data-loading>
        @csrf
        <input type="hidden" name="device_signal" data-device-signal>

        <div class="field">
            <label class="form-label" for="first_name">First name</label>
            <input class="form-input form-input-flow" id="first_name" name="first_name" type="text" autocomplete="given-name" required value="{{ old('first_name', $draft['first_name'] ?? '') }}"
                aria-invalid="{{ $errors->has('first_name') ? 'true' : 'false' }}"
                @if ($errors->has('first_name')) aria-describedby="first_name-error" @endif>
            @error('first_name') <p class="form-error" id="first_name-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="form-label" for="last_name">Last name</label>
            <input class="form-input form-input-flow" id="last_name" name="last_name" type="text" autocomplete="family-name" required value="{{ old('last_name', $draft['last_name'] ?? '') }}"
                aria-invalid="{{ $errors->has('last_name') ? 'true' : 'false' }}"
                @if ($errors->has('last_name')) aria-describedby="last_name-error" @endif>
            @error('last_name') <p class="form-error" id="last_name-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="form-label" for="phone">Mobile phone</label>
            <input class="form-input form-input-flow" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required value="{{ old('phone', $draft['phone'] ?? '') }}"
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
                <input class="form-input form-input-flow" id="email" name="email" type="email" autocomplete="email" value="{{ old('email', $draft['email'] ?? '') }}"
                    aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                    @if ($errors->has('email')) aria-describedby="email-error" @endif>
                @error('email') <p class="form-error" id="email-error">{{ $message }}</p> @enderror
            </div>
        @endif

        <label class="well mt-5 flex items-start gap-3 text-base">
            <input type="checkbox" name="consent" value="1" class="mt-0.5 h-[22px] w-[22px] flex-none accent-brand-600" @checked(old('consent', $draft['consent'] ?? false))>
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
