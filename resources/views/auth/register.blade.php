@extends('layouts.auth')

@section('title', 'Create your business account')

@section('content')
    <h1 class="type-display-xs text-text-1">Create your business account</h1>
    <p class="type-body mt-2 text-text-2">Three short steps to your account. You add your address next, while you set up.</p>

    {{-- Step-list wizard presentation (design-system.md Sections 3.3
         and 6.7 FND-5): one server submission at the Step 3
         identity checkpoint validates every field
         (RegisterBusinessRequest) and creates the account
         (RS2-2: the signup persists from this moment; the
         address is collected later, in onboarding). On a failed
         submit the wizard reopens on the step holding the first
         problem, and every problem is stated at its field, in
         plain language (F-03); the banner above counts AND lists
         every problem, cross-step account errors included
         (single-source summary, 2026-10-29). Without JavaScript
         every step stays visible and the form submits exactly
         as before. --}}
    @php
        $stepFields = [
            1 => ['business_name', 'business_type'],
            2 => ['name', 'email', 'phone'],
            3 => ['password', 'password_confirmation'],
        ];
        $errorStep = 1;
        foreach ($stepFields as $stepNumber => $fields) {
            if ($errors->hasAny($fields)) {
                $errorStep = $stepNumber;
                break;
            }
        }
    @endphp
    <form method="POST" action="{{ route('register.store') }}" x-data="{ step: {{ $errorStep }}, maxReached: {{ $errors->any() ? 3 : 1 }}, pw: '' }" class="mt-6" data-loading>
        @csrf

        {{-- AU-2/AU-3 (R5 pack A2 pick C): the segmented progress
             becomes a named step rail. Each step is named once,
             here (the fieldset legends are now screen-reader-only,
             so the step is no longer announced twice); the current
             step carries aria-current="step"; a completed step is
             a button back to it; a step not yet reached is
             disabled. The position line keeps only the count and
             the price fact. --}}
        <div class="mb-8">
            <ol class="flex gap-1.5">
                @foreach (['Business', 'Contact', 'Password'] as $i => $railName)
                    @php($n = $i + 1)
                    <li class="flex-1">
                        <button type="button"
                            class="block w-full text-left"
                            @click="if ({{ $n }} <= maxReached) step = {{ $n }}"
                            :disabled="{{ $n }} > maxReached"
                            :aria-current="step === {{ $n }} ? 'step' : undefined">
                            <span class="segment block" :class="step >= {{ $n }} ? 'segment-on' : ''" aria-hidden="true"></span>
                            <span class="type-caption mt-2 block" :class="step === {{ $n }} ? 'font-semibold text-text-1' : 'text-text-2'">{{ $railName }}</span>
                        </button>
                    </li>
                @endforeach
            </ol>
            <p class="mt-3 flex items-baseline gap-2">
                <span class="type-caption font-semibold text-text-1" x-text="'Step ' + step + ' of 3'">Step 1 of 3</span>
                <span class="type-caption ml-auto font-medium text-success-strong">Free to start</span>
            </p>
        </div>

        <fieldset x-show="step === 1" x-cloak>
            <legend class="sr-only">Step 1: Business Information</legend>
            @if ($claimPage ?? null)
                {{-- G1-C27 (claim path only): Step 1 renders as a
                     confirm because the values are prefilled. --}}
                <p class="form-help">{{ \App\Support\ClaimableCopy::c27($claimPage) }}</p>
            @else
                <p class="form-help">Tell us about your business.</p>
            @endif

            <label for="business_name">Business Name</label>
            <input id="business_name" name="business_name" type="text" value="{{ old('business_name', $prefillName ?? '') }}" required autocomplete="organization"
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
            <legend class="sr-only">Step 2: Contact Details</legend>
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
            <input id="phone" name="phone" type="tel" value="{{ old('phone', $prefillPhone ?? '') }}" required autocomplete="tel"
                aria-invalid="{{ $errors->has('phone') ? 'true' : 'false' }}"
                @if ($errors->has('phone')) aria-describedby="phone-error" @endif>
            @error('phone') <p class="form-error" id="phone-error">{{ $message }}</p> @enderror
            @if (($claimPage ?? null) && old('phone', $prefillPhone ?? '') === $claimPage->listed_phone_e164)
                {{-- G1-C26 (§5.4): renders only while the field
                     still holds the snapshot's listed number. --}}
                <p class="form-help">{{ \App\Support\ClaimableCopy::c26($claimPage) }}</p>
            @endif
        </fieldset>

        <fieldset x-show="step === 3" x-cloak>
            <legend class="sr-only">Step 3: Create Password</legend>
            <x-password-input name="password" label="Create Password" autocomplete="new-password" required x-model="pw" describedby="password-rules" />
            {{-- AUTH-UP-03 via the shared component (AU-6):
                 register and reset render the same rules. --}}
            <x-password-rules model="pw" />
            <x-password-input name="password_confirmation" label="Confirm password" autocomplete="new-password" required />
        </fieldset>

        @if (config('services.turnstile.site_key'))
            <div class="cf-turnstile mt-6" data-sitekey="{{ config('services.turnstile.site_key') }}" data-theme="auto"></div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endif

        <div class="mt-8 flex flex-col-reverse gap-3 sm:flex-row sm:items-center">
            <button type="button" class="btn btn-ghost h-12 w-full sm:w-auto" x-show="step > 1" x-cloak @click="step = step - 1">Previous</button>
            <button type="button" class="btn btn-primary h-12 w-full sm:ml-auto sm:w-auto sm:min-w-[160px]" x-show="step < 3" x-cloak
                @click="if ($el.closest('form').querySelectorAll('fieldset')[step - 1].querySelectorAll(':invalid').length === 0) { step = step + 1; maxReached = Math.max(maxReached, step) } else { $el.closest('form').reportValidity() }">Next</button>
            <button type="submit" class="btn btn-primary h-12 w-full sm:ml-auto sm:w-auto sm:min-w-[160px]" x-show="step === 3" x-cloak>Create account</button>
        </div>
        @if ($claimPage ?? null)
            {{-- G1-C21: true only before the checkpoint; this form
                 IS the pre-checkpoint surface. --}}
            <p class="form-help mt-4">{{ \App\Support\ClaimableCopy::c21() }}</p>
        @endif
    </form>

    <p class="mt-6 text-base">Already have an account? {{-- TX-D4 (release 08d): 24px link box; bare inline it measured 20px. --}}<a class="inline-flex min-h-6 items-center font-semibold text-link underline" href="{{ route('login') }}">Sign in</a></p>
@endsection
