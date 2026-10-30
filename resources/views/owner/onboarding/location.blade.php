@extends('layouts.onboarding')

@section('title', 'Where is your business?')

@section('content')
    @include('owner.onboarding._progress')

    {{-- RS2-2 Location step: the address that used to be signup
         Step 4, collected here since the identity checkpoint
         creates the account at signup Step 3. The reason line
         names only what is true in every state the step renders
         in: customers see the street and city on the page the
         code opens (the flow landing carries the line once an
         address exists, and omits it while none does). The save
         is one tap (save + advance); a saved address stays
         editable here and in Settings, Profile. --}}
    <h1 class="type-title-2 text-text-1">Where is your business?</h1>
    <p class="type-body mt-2 text-text-2">Customers see your street and city on the page your code opens.</p>
    @if ($business->claim_source === 'claimable_page' && $business->street)
        {{-- G1-C28 (claim path only): true only while the address
             shown is the snapshot's; the form below is prefilled
             from it for a one-tap confirm. --}}
        <p class="type-body mt-2 text-text-2">{{ \App\Support\ClaimableCopy::c28() }}</p>
    @endif

    @if ($done)
        <div class="panel mt-6">
            <p class="text-text-1"><span class="font-semibold">✓ Address saved.</span></p>
            <p class="mt-2 text-text-1">{{ $business->street }}, {{ $business->city }}</p>
            <p class="mt-4"><a class="btn btn-primary" href="{{ route('onboarding.step', $next) }}">Continue</a></p>
        </div>
    @endif

    <form method="POST" action="{{ route('onboarding.location.store') }}" class="panel mt-6" data-loading>
        @csrf

        <label for="country">Country</label>
        {{-- AUTH-UP-02 carried over from the old signup step: a
             searchable country select; the options render country
             names, the ISO codes stay the values (F-19). --}}
        <select id="country" name="country" required aria-label="Search or select your country"
            aria-invalid="{{ $errors->has('country') ? 'true' : 'false' }}"
            @if ($errors->has('country')) aria-describedby="country-error" @endif>
            @foreach (config('countries.codes') as $code)
                <option value="{{ $code }}" @selected(old('country', $business->country ?: 'US') === $code)>{{ config('countries.names')[$code] ?? $code }}</option>
            @endforeach
        </select>
        @error('country') <p class="form-error" id="country-error">{{ $message }}</p> @enderror

        <label for="street">Street Address</label>
        <input id="street" name="street" type="text" value="{{ old('street', $business->street) }}" required autocomplete="street-address"
            aria-invalid="{{ $errors->has('street') ? 'true' : 'false' }}"
            @if ($errors->has('street')) aria-describedby="street-error" @endif>
        @error('street') <p class="form-error" id="street-error">{{ $message }}</p> @enderror

        <label for="city">City</label>
        <input id="city" name="city" type="text" value="{{ old('city', $business->city) }}" required autocomplete="address-level2"
            aria-invalid="{{ $errors->has('city') ? 'true' : 'false' }}"
            @if ($errors->has('city')) aria-describedby="city-error" @endif>
        @error('city') <p class="form-error" id="city-error">{{ $message }}</p> @enderror

        <label for="state">State/Province</label>
        <input id="state" name="state" type="text" value="{{ old('state', $business->state) }}" required autocomplete="address-level1"
            aria-invalid="{{ $errors->has('state') ? 'true' : 'false' }}"
            @if ($errors->has('state')) aria-describedby="state-error" @endif>
        @error('state') <p class="form-error" id="state-error">{{ $message }}</p> @enderror

        <label for="postal_code">Postal Code</label>
        <input id="postal_code" name="postal_code" type="text" value="{{ old('postal_code', $business->postal_code) }}" required autocomplete="postal-code"
            aria-invalid="{{ $errors->has('postal_code') ? 'true' : 'false' }}"
            @if ($errors->has('postal_code')) aria-describedby="postal_code-error" @endif>
        @error('postal_code') <p class="form-error" id="postal_code-error">{{ $message }}</p> @enderror

        <p class="mt-4"><button type="submit" class="btn btn-primary">{{ $done ? 'Save changes' : 'Save and continue' }}</button></p>
    </form>

    <p class="mt-6">
        <a class="text-action" href="{{ route('onboarding.step', $next) }}">Skip this step</a>
    </p>
    @unless ($done)
        <p class="type-body-sm mt-2 text-text-2">You can add your address any time in Settings. Until you do, your page shows your business name without an address line.</p>
    @endunless
@endsection
