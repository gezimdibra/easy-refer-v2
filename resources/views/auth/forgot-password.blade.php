@extends('layouts.auth')

@section('title', 'Reset your password')

@section('content')
    <h1 class="type-display-xs text-text-1">Reset your password</h1>
    <p class="type-body mt-2 text-text-2">Enter the email on your account and we will send you a link to set a new password. The link works for 60 minutes and can be used once.</p>

    <form method="POST" action="{{ route('password.email') }}" class="mt-8" data-loading>
        @csrf

        <div class="field"><label class="form-label" for="email">Email</label>
        <input class="form-input" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email"></div>

        @if (config('services.turnstile.site_key'))
            <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}" data-theme="auto"></div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endif

        <p class="mt-6"><button type="submit" class="btn btn-primary h-12 w-full">Email the reset link</button></p>
    </form>

    <p class="mt-6 text-center"><a class="inline-flex min-h-11 items-center font-medium text-link underline decoration-brand-100 underline-offset-[3px] hover:decoration-brand-700" href="{{ route('login') }}">Back to sign in</a></p>
@endsection
