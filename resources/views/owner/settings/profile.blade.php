@extends('layouts.app')

@section('title', 'Business profile')

@section('notices_summary_only', '1')

@section('content')
    <h1 class="text-2xl font-bold">Business profile</h1>

    @include('owner.settings.nav')

    <form method="POST" action="{{ route('settings.profile.update') }}" class="card">
        @csrf

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
        <input id="state" name="state" type="text" value="{{ old('state', $business->state) }}" required autocomplete="address-level1"
            aria-invalid="{{ $errors->has('state') ? 'true' : 'false' }}"
            @if ($errors->has('state')) aria-describedby="state-error" @endif>
        @error('state') <p class="form-error" id="state-error">{{ $message }}</p> @enderror

        <label for="postal_code">Postal code</label>
        <input id="postal_code" name="postal_code" type="text" value="{{ old('postal_code', $business->postal_code) }}" required autocomplete="postal-code"
            aria-invalid="{{ $errors->has('postal_code') ? 'true' : 'false' }}"
            @if ($errors->has('postal_code')) aria-describedby="postal_code-error" @endif>
        @error('postal_code') <p class="form-error" id="postal_code-error">{{ $message }}</p> @enderror

        <label for="country">Country</label>
        <select id="country" name="country"
            aria-invalid="{{ $errors->has('country') ? 'true' : 'false' }}"
            @if ($errors->has('country')) aria-describedby="country-error" @endif>
            @foreach (config('countries.codes') as $code)
                <option value="{{ $code }}" @selected(old('country', $business->country) === $code)>{{ config('countries.names')[$code] ?? $code }}</option>
            @endforeach
        </select>
        @error('country') <p class="form-error" id="country-error">{{ $message }}</p> @enderror

        <p class="mt-4"><button type="submit" class="btn btn-primary">Save changes</button></p>
    </form>
@endsection
