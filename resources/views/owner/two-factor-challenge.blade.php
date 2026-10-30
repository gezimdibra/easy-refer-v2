@extends('layouts.auth')

@section('title', 'Two-factor verification')

@section('content')
    <h1 class="type-display-xs text-text-1">Two-factor verification</h1>

    {{-- AU-9 (R5 pack A7 pick B): the challenge adopts the flow's
         six-box code component (shared [data-otp] behavior, OTP-01
         fixes included). Recovery codes get an explicit alternate
         mode with its own field and format hint; the mode is a
         server-rendered presentation toggle and both modes post
         the one 'code' field, so the POST semantics never change.
         Toggle labels and hint: Amendment 26 R5-AUTH-06 (PROPOSED). --}}
    @if ($mode === 'recovery')
        <p class="type-body mt-2 text-text-2">Enter one of your recovery codes instead.</p>

        <form class="mt-8" method="POST" action="{{ route('two-factor.challenge.store') }}" data-loading>
            @csrf

            <div class="field">
                <label class="form-label" for="code">Recovery code</label>
                <input class="form-input" id="code" name="code" type="text" autocomplete="one-time-code" required autofocus aria-describedby="recovery-hint">
                <p class="form-help" id="recovery-hint">Recovery codes look like XXXXX-XXXXX.</p>
            </div>

            <p class="mt-6"><button type="submit" class="btn btn-primary h-12 w-full">Verify and continue</button></p>
        </form>

        <p class="mt-4 text-center"><a class="inline-flex min-h-11 items-center font-medium text-link underline decoration-brand-100 underline-offset-[3px] hover:decoration-brand-700" href="{{ route('two-factor.challenge') }}">Use an authenticator code instead</a></p>
    @else
        <p class="type-body mt-2 text-text-2">Enter the six-digit code from your authenticator app.</p>

        <form class="mt-8" method="POST" action="{{ route('two-factor.challenge.store') }}" data-loading>
            @csrf

            <div class="field">
                <span class="form-label" id="code_label">6-digit code</span>
                <div class="flex gap-2 {{ $errors->has('code') ? 'otp-group-error' : '' }}" role="group" aria-labelledby="code_label" data-otp>
                    @for ($i = 1; $i <= 6; $i++)
                        <input class="otp-box flex-1 text-center" name="code_{{ $i }}" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" data-otp-box @if ($i === 1) autocomplete="one-time-code" autofocus @endif aria-label="Digit {{ $i }}" aria-invalid="{{ $errors->has('code') ? 'true' : 'false' }}">
                    @endfor
                </div>
                <input type="hidden" name="code" id="code" data-otp-value>
            </div>

            <p class="mt-6"><button type="submit" class="btn btn-primary h-12 w-full">Verify and continue</button></p>
        </form>

        <p class="mt-4 text-center"><a class="inline-flex min-h-11 items-center font-medium text-link underline decoration-brand-100 underline-offset-[3px] hover:decoration-brand-700" href="{{ route('two-factor.challenge', ['mode' => 'recovery']) }}">Use a recovery code instead</a></p>
    @endif

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn btn-ghost w-full">Sign out</button>
    </form>
@endsection
