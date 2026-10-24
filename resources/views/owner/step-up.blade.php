@extends('layouts.auth')

@section('title', 'Confirm it is you')

@section('content')
    <h1 class="type-display-xs text-text-1">Confirm it is you</h1>
    <p class="type-body mt-2 text-text-2">This change needs a fresh check before it can go ahead. Re-enter your password@if ($user->hasTwoFactorEnabled()), or enter a code from your authenticator app@endif.</p>

    <form class="mt-8" method="POST" action="{{ route('security.step-up.store') }}" data-loading>
        @csrf

        <x-password-input name="password" label="Current password" :required="! $user->hasTwoFactorEnabled()" />

        @if ($user->hasTwoFactorEnabled())
            <label class="form-label" for="code">Authenticator code</label>
            <input class="form-input" id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code">
        @endif

        <p class="mt-6"><button type="submit" class="btn btn-primary h-12 w-full">Verify</button></p>
    </form>

    <p class="mt-6 text-center"><a class="inline-flex min-h-11 items-center font-medium text-brand-700 underline decoration-brand-100 underline-offset-[3px] hover:decoration-brand-700" href="{{ route('security') }}">Back to account security</a></p>
@endsection
