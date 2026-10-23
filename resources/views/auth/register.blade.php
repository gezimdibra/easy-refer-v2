@extends('layouts.auth')

@section('title', 'Create your business account')
@section('notices_summary_only', '1')
@section('notices_banner_keys', 'account')

@section('content')
    <h1 class="text-[32px] leading-[38px] font-bold text-text-1">Create your business account</h1>
    <p class="mt-2 text-base text-text-2">Four short steps. Your account is created when you submit the last step.</p>

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

        <ol class="mb-6 flex flex-wrap gap-x-4 gap-y-2 text-sm" aria-label="Sign up steps">
            @foreach (['Business Information', 'Contact Details', 'Create Password', 'Location'] as $i => $label)
                <li>
                    <button type="button"
                        class="font-medium"
                        :class="step === {{ $i + 1 }} ? 'font-semibold text-text-1' : ({{ $i + 1 }} < step ? 'text-brand-700' : 'text-text-3')"
                        @click="if ({{ $i + 1 }} <= maxReached) { step = {{ $i + 1 }} }"
                        :aria-current="step === {{ $i + 1 }} ? 'step' : null">
                        {{ $i + 1 }}. {{ $label }}
                    </button>
                </li>
            @endforeach
        </ol>

        <fieldset x-show="step === 1" x-cloak>
            <legend>Step 1: Business Information</legend>
            <p class="form-help">Tell us about your business.</p>

            <label for="business_name">Business Name</label>
            <input id="business_name" name="business_name" type="text" value="{{ old('business_name') }}" required autofocus
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
            <input id="name" name="name" type="text" value="{{ old('name') }}" required
                aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}"
                @if ($errors->has('name')) aria-describedby="name-error" @endif>
            @error('name') <p class="form-error" id="name-error">{{ $message }}</p> @enderror

            <label for="email">Email Address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required
                aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                @if ($errors->has('email')) aria-describedby="email-error" @endif>
            @error('email') <p class="form-error" id="email-error">{{ $message }}</p> @enderror

            <label for="phone">Phone Number</label>
            <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" required
                aria-invalid="{{ $errors->has('phone') ? 'true' : 'false' }}"
                @if ($errors->has('phone')) aria-describedby="phone-error" @endif>
            @error('phone') <p class="form-error" id="phone-error">{{ $message }}</p> @enderror
        </fieldset>

        <fieldset x-show="step === 3" x-cloak>
            <legend>Step 3: Create Password</legend>
            <label for="password">Create Password</label>
            <input id="password" name="password" type="password" required x-model="pw"
                aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                @if ($errors->has('password')) aria-describedby="password-rules password-error" @else aria-describedby="password-rules" @endif>
            {{-- AUTH-UP-03: both rules, stated as the inventory
                 states them; the length rule ticks live. --}}
            <ul class="form-help list-inside list-disc" id="password-rules">
                <li><span x-show="pw.length >= 12" x-cloak aria-hidden="true">&#10003; </span>Use at least 12 characters.</li>
                <li>Avoid passwords you have used anywhere else.</li>
            </ul>
            @error('password') <p class="form-error" id="password-error">{{ $message }}</p> @enderror

            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required
                aria-invalid="{{ $errors->has('password_confirmation') ? 'true' : 'false' }}"
                @if ($errors->has('password_confirmation')) aria-describedby="password_confirmation-error" @endif>
            @error('password_confirmation') <p class="form-error" id="password_confirmation-error">{{ $message }}</p> @enderror
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
            <input id="street" name="street" type="text" value="{{ old('street') }}" required
                aria-invalid="{{ $errors->has('street') ? 'true' : 'false' }}"
                @if ($errors->has('street')) aria-describedby="street-error" @endif>
            @error('street') <p class="form-error" id="street-error">{{ $message }}</p> @enderror

            <label for="city">City</label>
            <input id="city" name="city" type="text" value="{{ old('city') }}" required
                aria-invalid="{{ $errors->has('city') ? 'true' : 'false' }}"
                @if ($errors->has('city')) aria-describedby="city-error" @endif>
            @error('city') <p class="form-error" id="city-error">{{ $message }}</p> @enderror

            <label for="state">State/Province</label>
            <input id="state" name="state" type="text" value="{{ old('state') }}" required
                aria-invalid="{{ $errors->has('state') ? 'true' : 'false' }}"
                @if ($errors->has('state')) aria-describedby="state-error" @endif>
            @error('state') <p class="form-error" id="state-error">{{ $message }}</p> @enderror

            <label for="postal_code">Postal Code</label>
            <input id="postal_code" name="postal_code" type="text" value="{{ old('postal_code') }}" required
                aria-invalid="{{ $errors->has('postal_code') ? 'true' : 'false' }}"
                @if ($errors->has('postal_code')) aria-describedby="postal_code-error" @endif>
            @error('postal_code') <p class="form-error" id="postal_code-error">{{ $message }}</p> @enderror
        </fieldset>

        @if (config('services.turnstile.site_key'))
            <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}"></div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endif

        <div class="mt-6 flex items-center gap-3">
            <button type="button" class="btn btn-ghost" x-show="step > 1" x-cloak @click="step = step - 1">Previous</button>
            <button type="button" class="btn btn-primary ml-auto" x-show="step < 4" x-cloak
                @click="if ($el.closest('form').querySelectorAll('fieldset')[step - 1].querySelectorAll(':invalid').length === 0) { step = step + 1; maxReached = Math.max(maxReached, step) } else { $el.closest('form').reportValidity() }">Next</button>
            <button type="submit" class="ml-auto" x-show="step === 4" x-cloak>Create Account</button>
        </div>
    </form>

    <p class="mt-6 text-base">Already have an account? <a class="font-semibold text-brand-700 underline" href="{{ route('login') }}">Sign in</a></p>
@endsection
