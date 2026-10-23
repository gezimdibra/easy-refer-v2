@extends('layouts.auth')

@section('title', 'Reset your password')

@section('content')
    <h1>Reset your password</h1>
    <p>Enter the email on your account and we will send you a link to set a new password. The link works for 60 minutes and can be used once.</p>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>

        @if (config('services.turnstile.site_key'))
            <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}"></div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endif

        <p><button type="submit">Email the reset link</button></p>
    </form>

    <p><a href="{{ route('login') }}">Back to sign in</a></p>
@endsection
