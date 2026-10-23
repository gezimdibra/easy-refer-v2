@extends('layouts.auth')

@section('title', 'Confirm it is you')

@section('content')
    <h1 class="text-[32px] leading-[38px] font-bold text-text-1">Confirm it is you</h1>
    <p>This change needs a fresh check before it can go ahead. Re-enter your password@if ($user->hasTwoFactorEnabled()), or enter a code from your authenticator app@endif.</p>

    <form method="POST" action="{{ route('security.step-up.store') }}">
        @csrf

        <label for="password">Current password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" @if (! $user->hasTwoFactorEnabled()) required @endif>

        @if ($user->hasTwoFactorEnabled())
            <label for="code">Authenticator code</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code">
        @endif

        <p><button type="submit">Verify</button></p>
    </form>

    <p><a href="{{ route('security') }}">Back to account security</a></p>
@endsection
