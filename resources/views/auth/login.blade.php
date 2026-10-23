@extends('layouts.auth')

@section('title', 'Sign in')
@section('notices_summary_only', '1')

@section('content')
    {{-- AUTH-IN-06, the header line. --}}
    <p class="text-text-2">Easy Refer: your one-stop platform for managing referrals.</p>

    {{-- AUTH-IN-01: heading and subheading. --}}
    <h1 class="mt-3 text-[32px] leading-[38px] font-bold text-text-1">Welcome back</h1>
    <p class="mt-1 text-text-2">Sign in to your account.</p>

    <form method="POST" action="{{ route('login.store') }}" class="mt-4">
        @csrf

        <label for="identifier">Email or phone number</label>
        <input id="identifier" name="identifier" type="text" value="{{ old('identifier') }}" required autofocus autocomplete="username"
            aria-invalid="{{ $errors->has('identifier') ? 'true' : 'false' }}"
            @if ($errors->has('identifier')) aria-describedby="identifier-error" @endif>
        @error('identifier')
            <p class="form-error" id="identifier-error">{{ $message }}</p>
        @enderror

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password"
            aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
            @if ($errors->has('password')) aria-describedby="password-error" @endif>
        @error('password')
            <p class="form-error" id="password-error">{{ $message }}</p>
        @enderror

        <label>
            <input type="checkbox" name="remember"> Remember me
        </label>

        @if (config('services.turnstile.site_key'))
            {{-- Required only when the account aggregate defense has
                 locked the account (spec Section 8.3); the server
                 verifies the token on that path. --}}
            <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}"></div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endif

        <p><button type="submit">Sign in</button></p>
    </form>

    <p><a href="{{ route('password.request') }}">Forgot Password?</a></p>
    <p>New to {{ config('app.name') }}? <a href="{{ route('register') }}">Create an account</a></p>
@endsection
