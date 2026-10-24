@extends('layouts.app')

@section('title', 'Set up two-factor authentication')

@section('content')
    <h1 class="type-title-2 text-text-1">Set up two-factor authentication</h1>

    <nav aria-label="Breadcrumb" class="type-caption mt-2 text-text-3">
        <a class="text-text-2 hover:text-text-1" href="{{ route('settings') }}">Settings</a>
        <span aria-hidden="true"> / </span>
        <a class="text-text-2 hover:text-text-1" href="{{ route('security') }}">Security</a>
        <span aria-hidden="true"> / </span>
        <span aria-current="page" class="text-text-1">Set up two-factor authentication</span>
    </nav>

    <p class="type-body mt-4 max-w-[68ch] text-text-2">Add this account to your authenticator app using the setup link below, or type the secret in by hand. Then enter the six-digit code the app shows to finish.</p>

    <div class="panel mt-6 max-w-[720px]">
        <p class="form-label">Setup link</p>
        <p class="well break-all font-mono text-sm">{{ $provisioningUri }}</p>
        <p class="form-label mt-5">Secret (for manual entry)</p>
        <div class="joined max-w-[560px]">
            <input class="form-input font-mono" type="text" readonly value="{{ $user->two_factor_secret }}" aria-label="Two-factor secret" onclick="this.select()">
            <button type="button" class="btn btn-secondary" onclick="navigator.clipboard && navigator.clipboard.writeText('{{ $user->two_factor_secret }}')"><x-icon name="copy" :size="20" /> Copy</button>
        </div>
    </div>

    <form method="POST" action="{{ route('security.two-factor.confirm.store') }}" class="panel mt-4 max-w-[720px] [&_input]:max-w-[560px]" data-loading>
        @csrf

        <x-password-input name="current_password" label="Current password" required autofocus />

        <label for="code">Code from your authenticator app</label>
        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required>

        <p class="mt-5"><button type="submit" class="btn btn-primary">Confirm and turn on</button></p>
    </form>

    <p class="mt-6"><a class="inline-flex min-h-11 items-center font-medium text-brand-700 underline decoration-brand-100 underline-offset-[3px] hover:decoration-brand-700" href="{{ route('security') }}">Back to account security</a></p>
@endsection
