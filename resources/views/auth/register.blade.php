@extends('layouts.auth')

@section('title', 'Create your business account')
@section('notices_summary_only', '1')
@section('notices_banner_keys', 'account')

@section('content')
    <h1 class="type-display-xs text-text-1">Create your business account</h1>
    <p class="type-body mt-2 text-text-2">Four short steps. Your account is created when you submit the last step.</p>

    {{-- Step-list wizard presentation (design-system.md Sections 3.3
         and 6.7 FND-5): one server submission validates every field
         (RegisterBusinessRequest). On a failed submit the wizard
         reopens on the step holding the first problem, and every
         problem is stated once, at its field, in plain language
         (F-03); the banner above carries only the summary line and
         cross-step account errors. Without JavaScript every step
         stays visible and the form submits exactly as before. --}}
    @php
        $stepFields = [
            1 => ['business_name', 'business_type'],
            2 => ['name', 'email', 'phone'],
            3 => ['password', 'password_confirmation'],
            4 => ['country', 'street', 'city', 'state', 'postal_code'],
        ];
        $errorStep = 1;
        foreach ($stepFields as $stepNumber => $fields) {
            if ($errors->hasAny($fields)) {
                $errorStep = $stepNumber;
                break;
            }
        }
    @endphp
    <form method="POST" action="{{ route('register.store') }}" x-data="{ step: {{ $errorStep }}, maxReached: {{ $errors->any() ? 4 : 1 }}, pw: '' }" class="mt-6">
        @csrf

        {{-- Segmented progress (redesign-spec.md Section 10.2):
             decorative geometry plus the step position in text, so
             no information lives only in the graphic. The step name
             updates with the wizard; the server render names step 1. --}}
        <div class="mb-8">
            <div class="flex gap-1.5" aria-hidden="true">
                @foreach ([1, 2, 3, 4] as $n)
                    <span class="segment" :class="step >= {{ $n }} ? 'segment-on' : ''"></span>
                @endforeach
            </div>
            <p class="mt-3 flex items-baseline gap-2">
                <span class="type-caption font-semibold text-text-1" x-text="'Step ' + step + ' of 4'">Step 1 of 4</span>
                <span class="type-caption text-text-2" x-text="['Business Information', 'Contact Details', 'Create Password', 'Location'][step - 1]">Business Information</span>
                <span class="type-caption ml-auto font-medium text-success-strong">Free to start</span>
            </p>
        </div>

        <fieldset x-show="step === 1" x-cloak>
            <legend>Step 1: Business Information</legend>
            <p class="form-help">Tell us about your business.</p>

            <label for="business_name">Business Name</label>
            <input id="business_name" name="business_name" type="text" value="{{ old('business_name') }}" required autocomplete="organization"
                aria-invalid="{{ $errors->has('business_name') ? 'true' : 'false' }}"
                @if ($errors->has('business_name')) aria-describedby="business_name-error" @endif>
            @error('business_name') <p class="form-error" id="business_name-error">{{ $message }}</p> @enderror

            <label for="business_type">Business type (optional)</label>
            <select id="business_type" name="business_type"
                aria-invalid="{{ $errors->has('business_type') ? 'true' : 'false' }}"
                @if ($errors->has('business_type')) aria-describedby="business_type-error" @endif>
                <option value="">No type selected</option>
                @foreach (\App\Models\Business::TYPES as $type)
                    <option value="{{ $type }}" @selected(old('business_type', $prefillType ?? null) === $type)>{{ ucfirst($type) }}</option>
                @endforeach
            </select>
            @error('business_type') <p class="form-error" id="business_type-error">{{ $message }}</p> @enderror
            <p class="form-help">Your type tailors the starter reward wording and examples we suggest later. You can change it any time in settings.</p>
        </fieldset>

        <fieldset x-show="step === 2" x-cloak>
            <legend>Step 2: Contact Details</legend>
            <label for="name">Your name</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" required autocomplete="name"
                aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}"
                @if ($errors->has('name')) aria-describedby="name-error" @endif>
            @error('name') <p class="form-error" id="name-error">{{ $message }}</p> @enderror

            <label for="email">Email Address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                @if ($errors->has('email')) aria-describedby="email-error" @endif>
            @error('email') <p class="form-error" id="email-error">{{ $message }}</p> @enderror

            <label for="phone">Phone Number</label>
            <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" required autocomplete="tel"
                aria-invalid="{{ $errors->has('phone') ? 'true' : 'false' }}"
                @if ($errors->has('phone')) aria-describedby="phone-error" @endif>
            @error('phone') <p class="form-error" id="phone-error">{{ $message }}</p> @enderror
        </fieldset>

        <fieldset x-show="step === 3" x-cloak>
            <legend>Step 3: Create Password</legend>
            <x-password-input name="password" label="Create Password" autocomplete="new-password" required x-model="pw" describedby="password-rules" />
            {{-- AUTH-UP-03: both rules, stated as the inventory
                 states them; the length rule ticks live. --}}
            <ul class="mt-2 space-y-1.5" id="password-rules">
                <li class="type-caption flex items-center gap-2 text-text-2">
                    <span x-show="pw.length >= 12" x-cloak class="text-success-strong"><x-icon name="check" :size="16" /></span>
                    <span x-show="pw.length < 12" class="text-line-strong"><x-icon name="circle" :size="16" /></span>
                    Use at least 12 characters.
                </li>
                <li class="type-caption flex items-center gap-2 text-text-2">
                    <span class="text-line-strong"><x-icon name="circle" :size="16" /></span>
                    Avoid passwords you have used anywhere else.
                </li>
            </ul>
            <x-password-input name="password_confirmation" label="Confirm password" autocomplete="new-password" required />
        </fieldset>

        <fieldset x-show="step === 4" x-cloak>
            <legend>Step 4: Location</legend>
            <label for="country">Country</label>
            {{-- AUTH-UP-02: a searchable country select. The options
                 render country names; the ISO codes stay the values
                 (F-19). --}}
            <select id="country" name="country" required aria-label="Search or select your country"
                aria-invalid="{{ $errors->has('country') ? 'true' : 'false' }}"
                @if ($errors->has('country')) aria-describedby="country-error" @endif>
                @foreach (config('countries.codes') as $code)
                    <option value="{{ $code }}" @selected(old('country', 'US') === $code)>{{ config('countries.names')[$code] ?? $code }}</option>
                @endforeach
            </select>
            @error('country') <p class="form-error" id="country-error">{{ $message }}</p> @enderror

            <label for="street">Street Address</label>
            <input id="street" name="street" type="text" value="{{ old('street') }}" required autocomplete="street-address"
                aria-invalid="{{ $errors->has('street') ? 'true' : 'false' }}"
                @if ($errors->has('street')) aria-describedby="street-error" @endif>
            @error('street') <p class="form-error" id="street-error">{{ $message }}</p> @enderror

            <label for="city">City</label>
            <input id="city" name="city" type="text" value="{{ old('city') }}" required autocomplete="address-level2"
                aria-invalid="{{ $errors->has('city') ? 'true' : 'false' }}"
                @if ($errors->has('city')) aria-describedby="city-error" @endif>
            @error('city') <p class="form-error" id="city-error">{{ $message }}</p> @enderror

            <label for="state">State/Province</label>
            <input id="state" name="state" type="text" value="{{ old('state') }}" required autocomplete="address-level1"
                aria-invalid="{{ $errors->has('state') ? 'true' : 'false' }}"
                @if ($errors->has('state')) aria-describedby="state-error" @endif>
            @error('state') <p class="form-error" id="state-error">{{ $message }}</p> @enderror

            <label for="postal_code">Postal Code</label>
            <input id="postal_code" name="postal_code" type="text" value="{{ old('postal_code') }}" required autocomplete="postal-code"
                aria-invalid="{{ $errors->has('postal_code') ? 'true' : 'false' }}"
                @if ($errors->has('postal_code')) aria-describedby="postal_code-error" @endif>
            @error('postal_code') <p class="form-error" id="postal_code-error">{{ $message }}</p> @enderror
        </fieldset>

        @if (config('services.turnstile.site_key'))
            <div class="cf-turnstile mt-6" data-sitekey="{{ config('services.turnstile.site_key') }}"></div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endif

        <div class="mt-8 flex flex-col-reverse gap-3 sm:flex-row sm:items-center">
            <button type="button" class="btn btn-ghost h-12 w-full sm:w-auto" x-show="step > 1" x-cloak @click="step = step - 1">Previous</button>
            <button type="button" class="btn btn-primary h-12 w-full sm:ml-auto sm:w-auto sm:min-w-[160px]" x-show="step < 4" x-cloak
                @click="if ($el.closest('form').querySelectorAll('fieldset')[step - 1].querySelectorAll(':invalid').length === 0) { step = step + 1; maxReached = Math.max(maxReached, step) } else { $el.closest('form').reportValidity() }">Next</button>
            <button type="submit" class="btn btn-primary h-12 w-full sm:ml-auto sm:w-auto sm:min-w-[160px]" x-show="step === 4" x-cloak>Create Account</button>
        </div>
    </form>

    <p class="mt-6 text-base">Already have an account? <a class="font-semibold text-brand-700 underline" href="{{ route('login') }}">Sign in</a></p>
@endsection
