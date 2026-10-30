@extends('layouts.auth')

@section('title', 'Confirm it is you')

@section('content')
    <h1 class="type-display-xs text-text-1">Confirm it is you</h1>
    <p class="type-body mt-2 text-text-2">This change needs a fresh check before it can go ahead. @if ($user->hasTwoFactorEnabled())Re-enter your password, or enter a code from your authenticator app.@else Re-enter your password.@endif</p>

    {{-- AU-11 (R5 pack A8) + AU-10: with 2FA on, a segmented
         either/or replaces the two stacked fields joined by "or":
         one challenge visible at a time, the hidden field submits
         empty exactly as an untouched field does today (the server
         already accepts either). Pure Alpine presentation. The
         code field takes the shared single-line code styling
         (inputmode numeric + one-time-code). --}}
    <form class="mt-8" method="POST" action="{{ route('security.step-up.store') }}" data-loading @if ($user->hasTwoFactorEnabled()) x-data="{ mode: 'password' }" @endif>
        @csrf

        @if ($user->hasTwoFactorEnabled())
            <p class="form-label" id="step-up-mode-label">Use either one:</p>
            <div class="segmented" role="group" aria-labelledby="step-up-mode-label">
                <button type="button" class="segmented-option" :class="{ 'segmented-option-on': mode === 'password' }" :aria-pressed="mode === 'password'" @click="mode = 'password'">Password</button>
                <button type="button" class="segmented-option" :class="{ 'segmented-option-on': mode === 'code' }" :aria-pressed="mode === 'code'" @click="mode = 'code'">Authenticator code</button>
            </div>

            <div x-show="mode === 'password'" class="mt-5">
                <x-password-input name="password" label="Current password" />
            </div>

            <div x-show="mode === 'code'" class="mt-5" x-cloak>
                <label class="form-label" for="code">Authenticator code</label>
                <input class="form-input" id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code">
            </div>
        @else
            <x-password-input name="password" label="Current password" required />
        @endif

        <p class="mt-6"><button type="submit" class="btn btn-primary h-12 w-full">Verify</button></p>
    </form>

    <p class="mt-6 text-center"><a class="inline-flex min-h-11 items-center font-medium text-link underline decoration-brand-100 underline-offset-[3px] hover:decoration-brand-700" href="{{ route('security') }}">Back to account security</a></p>
@endsection
