@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
    <h1>Sign in to your business portal</h1>

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <label for="identifier">Email or phone number</label>
        <input id="identifier" name="identifier" type="text" value="{{ old('identifier') }}" required autofocus autocomplete="username">

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">

        <label>
            <input type="checkbox" name="remember" style="width: auto;"> Remember me
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

    <p><a href="{{ route('password.request') }}">Forgot your password?</a></p>
    <p>New to {{ config('app.name') }}? <a href="{{ route('register') }}">Create a business account</a></p>
@endsection
