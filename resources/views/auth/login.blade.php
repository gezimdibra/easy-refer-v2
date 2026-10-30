@extends('layouts.auth')

@section('title', 'Sign in')

@section('content')
    {{-- AU-1 (R5 pack A1): the eyebrow leaves the login. It
         competed with the heading and its job (name the program)
         is done by the panel beside the form and the heading
         itself; AUTH-IN-06's sentence survives on the marketing
         surfaces where it is the pitch, not a caption. --}}
    <h1 class="type-display-xs text-text-1">Welcome back</h1>
    <p class="type-body mt-2 text-text-2">Sign in to your account.</p>

    <form method="POST" action="{{ route('login.store') }}" class="mt-8" data-loading>
        @csrf

        <div class="field">
            <label class="form-label" for="identifier">Email or phone number</label>
            <input class="form-input" id="identifier" name="identifier" type="text" value="{{ old('identifier') }}" required autocomplete="username"
                aria-invalid="{{ $errors->has('identifier') ? 'true' : 'false' }}"
                @if ($errors->has('identifier')) aria-describedby="identifier-error" @endif>
            @error('identifier')
                <p class="form-error" id="identifier-error">{{ $message }}</p>
            @enderror
        </div>

        <x-password-input name="password" label="Password" autocomplete="current-password" required />

        <div class="mt-5 flex items-center justify-between gap-4">
            <label class="flex min-h-[48px] items-center gap-2.5 text-[15px] text-text-1">
                <input type="checkbox" name="remember" class="h-[18px] w-[18px] accent-action" style="border-radius: 5px"> Remember me
            </label>
            <a class="inline-flex min-h-11 items-center font-medium text-link underline decoration-brand-100 underline-offset-[3px] hover:decoration-brand-700" href="{{ route('password.request') }}">Forgot password?</a>
        </div>

        @if (config('services.turnstile.site_key'))
            {{-- Required only when the account aggregate defense has
                 locked the account (spec Section 8.3); the server
                 verifies the token on that path. --}}
            <div class="cf-turnstile mt-4" data-sitekey="{{ config('services.turnstile.site_key') }}" data-theme="auto"></div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endif

        <p class="mt-6"><button type="submit" class="btn btn-primary h-12 w-full">Sign in</button></p>
    </form>

    <div class="mt-8 flex items-center gap-4" aria-hidden="true">
        <span class="h-px flex-1 bg-line"></span>
        <span class="type-caption text-text-2">New to {{ config('app.name') }}?</span>
        <span class="h-px flex-1 bg-line"></span>
    </div>
    <p class="mt-4"><a class="btn btn-secondary h-12 w-full" href="{{ route('register') }}">Create an account</a></p>
@endsection
