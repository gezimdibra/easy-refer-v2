@extends('layouts.app')

@section('title', 'Set up two-factor authentication')

@section('content')
    <h1 class="type-title-2 text-text-1">Set up two-factor authentication</h1>

    <nav aria-label="Breadcrumb" class="type-caption mt-2 text-text-2">
        <a class="text-text-2 hover:text-text-1" href="{{ route('settings') }}">Settings</a>
        <span aria-hidden="true"> / </span>
        <a class="text-text-2 hover:text-text-1" href="{{ route('security') }}">Security</a>
        <span aria-hidden="true"> / </span>
        <span aria-current="page" class="text-text-1">Set up two-factor authentication</span>
    </nav>

    <p class="type-body mt-4 max-w-[68ch] text-text-2">Scan the code with your authenticator app, or enter the secret by hand. Then enter the six-digit code the app shows to finish.</p>

    {{-- AU-12/AU-13 (R5 pack A9 pick B): QR-first, two numbered
         steps. The raw provisioning URL well is gone; the URI
         lives only inside the QR image. The manual path is a
         disclosure with the secret grouped in 4s. --}}
    <div class="panel mt-6 max-w-[720px]">
        <h2 class="type-title-4 text-text-1"><span class="text-text-2">Step 1.</span> Scan this code with your authenticator app</h2>

        <p class="mt-4">
            <span class="qr-stage"><img src="{{ route('security.two-factor.qr') }}" alt="Two-factor setup QR code" width="232" height="232"></span>
        </p>

        <details class="mt-5">
            <summary class="inline-flex min-h-11 cursor-pointer items-center font-medium text-link underline decoration-brand-100 underline-offset-[3px] hover:decoration-brand-700">Can't scan it? Enter the secret by hand</summary>
            <div class="joined mt-3 max-w-[560px]">
                <input class="form-input font-mono" type="text" readonly value="{{ implode(' ', str_split((string) $user->two_factor_secret, 4)) }}" aria-label="Two-factor secret" onclick="this.select()">
                <button type="button" class="btn btn-secondary" onclick="navigator.clipboard && navigator.clipboard.writeText('{{ $user->two_factor_secret }}')"><x-icon name="copy" :size="20" /> Copy</button>
            </div>
        </details>
    </div>

    <form method="POST" action="{{ route('security.two-factor.confirm.store') }}" class="panel mt-4 max-w-[720px] [&_input]:max-w-[560px]" data-loading>
        @csrf

        <h2 class="type-title-4 text-text-1"><span class="text-text-2">Step 2.</span> Enter the code the app shows</h2>

        <div class="mt-4">
            <label for="code">Code from your authenticator app</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required autofocus>
        </div>

        {{-- AU-13: the code comes before the password (the pack's
             focus ruling: the page no longer opens by demanding a
             password for a task whose first step is in another
             app). The password guard itself is unchanged. --}}
        <div class="mt-4">
            <x-password-input name="current_password" label="Current password" required />
        </div>

        <p class="mt-5"><button type="submit" class="btn btn-primary">Confirm and turn on</button></p>
    </form>

    <p class="mt-6"><a class="inline-flex min-h-11 items-center font-medium text-link underline decoration-brand-100 underline-offset-[3px] hover:decoration-brand-700" href="{{ route('security') }}">Back to account security</a></p>
@endsection
