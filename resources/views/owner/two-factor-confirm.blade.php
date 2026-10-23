@extends('layouts.app')

@section('title', 'Set up two-factor authentication')

@section('content')
    <h1 class="text-[32px] leading-[38px] font-bold text-text-1">Set up two-factor authentication</h1>

    <p>Add this account to your authenticator app using the setup link below, or type the secret in by hand. Then enter the six-digit code the app shows to finish.</p>

    <p>Setup link:</p>
    <p><code>{{ $provisioningUri }}</code></p>

    <p>Secret (for manual entry): <code>{{ $user->two_factor_secret }}</code></p>

    <form method="POST" action="{{ route('security.two-factor.confirm.store') }}">
        @csrf

        <label for="current_password">Current password</label>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required autofocus>

        <label for="code">Code from your authenticator app</label>
        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required>

        <p><button type="submit">Confirm and turn on</button></p>
    </form>

    <p><a href="{{ route('security') }}">Back to account security</a></p>
@endsection
