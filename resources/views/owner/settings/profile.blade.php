@extends('layouts.app')

@section('title', 'Business profile')


{{-- SP-2: the error summary renders in the layout notices region
     (the single-source summary, 2026-10-29 class fix). --}}

@section('content')
    <h1 class="type-title-2 text-text-1">Business profile</h1>

    <p class="type-body mt-2 max-w-[68ch] text-text-2">How your business appears to customers, and how we reach you.</p>


    <div class="mt-6 lg:flex lg:items-start lg:gap-8">
        <div class="lg:w-[220px] lg:flex-none">
            @include('owner.settings.nav')
        </div>
        <div class="mt-6 min-w-0 flex-1 lg:mt-0">

    <form method="POST" action="{{ route('settings.profile.update') }}" x-data="{ country: @js(old('country', $business->country)) }" class="panel [&_input]:max-w-[560px] [&_select]:max-w-[560px] [&_textarea]:max-w-[560px]" data-loading>
        @csrf


        {{-- SP-1 (gap pack 05): the form groups under quiet
             headings; one form, one Save, no field, validation,
             or save behavior changes. --}}
        <h2 class="type-title-3 mt-2 text-text-1">Your business</h2>
        <p class="type-body-sm mt-1 text-text-2">This name is on your posters and the pages customers see.</p>

        <label for="name">Business name</label>
        <input id="name" name="name" type="text" value="{{ old('name', $business->name) }}" required
            aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}"
            @if ($errors->has('name')) aria-describedby="name-error" @endif>
        @error('name') <p class="form-error" id="name-error">{{ $message }}</p> @enderror

        <label for="business_type">Business type</label>
        <select id="business_type" name="business_type"
            aria-invalid="{{ $errors->has('business_type') ? 'true' : 'false' }}"
            @if ($errors->has('business_type')) aria-describedby="business_type-error" @endif>
            <option value="">No type selected</option>
            @foreach (\App\Models\Business::TYPES as $type)
                <option value="{{ $type }}" @selected(old('business_type', $business->business_type) === $type)>{{ ucfirst($type) }}</option>
            @endforeach
        </select>
        @error('business_type') <p class="form-error" id="business_type-error">{{ $message }}</p> @enderror

        <h2 class="type-title-3 mt-6 text-text-1">How we reach you</h2>

        <label for="contact_email">Contact email</label>
        <input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email', $business->contact_email) }}" required autocomplete="email"
            aria-invalid="{{ $errors->has('contact_email') ? 'true' : 'false' }}"
            @if ($errors->has('contact_email')) aria-describedby="contact_email-error" @endif>
        @error('contact_email') <p class="form-error" id="contact_email-error">{{ $message }}</p> @enderror

        <label for="contact_phone">Contact phone</label>
        <input id="contact_phone" name="contact_phone" type="tel" value="{{ old('contact_phone', $business->contact_phone_e164 ? \App\Support\PhoneNumber::formatForDisplay($business->contact_phone_e164) : '') }}" required autocomplete="tel"
            aria-invalid="{{ $errors->has('contact_phone') ? 'true' : 'false' }}"
            @if ($errors->has('contact_phone')) aria-describedby="contact_phone-error" @endif>
        @error('contact_phone') <p class="form-error" id="contact_phone-error">{{ $message }}</p> @enderror

        <h2 class="type-title-3 mt-6 text-text-1">Address</h2>

        <label for="street">Street address</label>
        <input id="street" name="street" type="text" value="{{ old('street', $business->street) }}" required autocomplete="street-address"
            aria-invalid="{{ $errors->has('street') ? 'true' : 'false' }}"
            @if ($errors->has('street')) aria-describedby="street-error" @endif>
        @error('street') <p class="form-error" id="street-error">{{ $message }}</p> @enderror

        <label for="city">City</label>
        <input id="city" name="city" type="text" value="{{ old('city', $business->city) }}" required autocomplete="address-level2"
            aria-invalid="{{ $errors->has('city') ? 'true' : 'false' }}"
            @if ($errors->has('city')) aria-describedby="city-error" @endif>
        @error('city') <p class="form-error" id="city-error">{{ $message }}</p> @enderror

        <label for="state">State</label>
        {{-- Same state control as signup: a US address picks from
             the real list of codes (the stored value stays the
             2-letter code); any other country keeps the free-text
             province field. The control that does not apply is
             disabled and hidden on the server render, so only one
             state value submits; Alpine swaps them when the
             country below changes. --}}
        @php($selectedState = \App\Support\UsState::normalize(old('state', $business->state)))
        @php($isUs = old('country', $business->country) === 'US')
        <select id="state" name="state" required autocomplete="address-level1"
            x-show="country === 'US'" :disabled="country !== 'US'"
            @if (! $isUs) disabled style="display: none" @endif
            aria-invalid="{{ $errors->has('state') ? 'true' : 'false' }}"
            @if ($errors->has('state')) aria-describedby="state-error" @endif>
            <option value="">Choose your state</option>
            @foreach (config('countries.us_states') as $stateCode => $stateName)
                <option value="{{ $stateCode }}" @selected($selectedState === $stateCode)>{{ $stateName }}</option>
            @endforeach
        </select>
        <input id="state_province" name="state" type="text" value="{{ $isUs ? '' : old('state', $business->state) }}" required autocomplete="address-level1"
            x-show="country !== 'US'" :disabled="country === 'US'"
            @if ($isUs) disabled style="display: none" @endif
            aria-invalid="{{ $errors->has('state') ? 'true' : 'false' }}"
            @if ($errors->has('state')) aria-describedby="state-error" @endif>
        @error('state') <p class="form-error" id="state-error">{{ $message }}</p> @enderror

        <label for="postal_code">Postal code</label>
        <input id="postal_code" name="postal_code" type="text" value="{{ old('postal_code', $business->postal_code) }}" required autocomplete="postal-code"
            aria-invalid="{{ $errors->has('postal_code') ? 'true' : 'false' }}"
            @if ($errors->has('postal_code')) aria-describedby="postal_code-error" @endif>
        @error('postal_code') <p class="form-error" id="postal_code-error">{{ $message }}</p> @enderror

        <label for="country">Country</label>
        <select id="country" name="country" x-model="country"
            aria-invalid="{{ $errors->has('country') ? 'true' : 'false' }}"
            @if ($errors->has('country')) aria-describedby="country-error" @endif>
            @foreach (config('countries.codes') as $code)
                <option value="{{ $code }}" @selected(old('country', $business->country) === $code)>{{ config('countries.names')[$code] ?? $code }}</option>
            @endforeach
        </select>
        @error('country') <p class="form-error" id="country-error">{{ $message }}</p> @enderror

        <p class="mt-4"><button type="submit" class="btn btn-primary">Save changes</button></p>
    </form>
        </div>
    </div>
@endsection
