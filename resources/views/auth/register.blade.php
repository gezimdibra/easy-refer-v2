@extends('layouts.app')

@section('title', 'Create your business account')

@section('content')
    <h1>Create your business account</h1>
    <p>Four short steps. Your account is created when you submit the last step.</p>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <fieldset>
            <legend>Step 1: Business information</legend>
            <label for="business_name">Business name</label>
            <input id="business_name" name="business_name" type="text" value="{{ old('business_name') }}" required autofocus>
        </fieldset>

        <fieldset>
            <legend>Step 2: Contact details</legend>
            <label for="name">Your name</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" required>

            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required>

            <label for="phone">Phone number</label>
            <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" required>
        </fieldset>

        <fieldset>
            <legend>Step 3: Create password</legend>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required>
            <p>At least 12 characters. A long phrase you can remember is strongest.</p>

            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required>
        </fieldset>

        <fieldset>
            <legend>Step 4: Location</legend>
            <label for="country">Country</label>
            <select id="country" name="country" required>
                @foreach (config('countries.codes') as $code)
                    <option value="{{ $code }}" @selected(old('country', 'US') === $code)>{{ $code }}</option>
                @endforeach
            </select>

            <label for="street">Street address</label>
            <input id="street" name="street" type="text" value="{{ old('street') }}" required>

            <label for="city">City</label>
            <input id="city" name="city" type="text" value="{{ old('city') }}" required>

            <label for="state">State or province</label>
            <input id="state" name="state" type="text" value="{{ old('state') }}" required>

            <label for="postal_code">ZIP or postal code</label>
            <input id="postal_code" name="postal_code" type="text" value="{{ old('postal_code') }}" required>
        </fieldset>

        <button type="submit">Create account</button>
    </form>

    <p>Already have an account? <a href="{{ route('login') }}">Sign in</a></p>
@endsection
