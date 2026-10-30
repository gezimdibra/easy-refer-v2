@extends('layouts.flow')

@section('title', 'Enter your code | Easy Refer')
@section('flow_label', 'Verification')

@section('step_number', '2')
@section('step_total', $stepTotal)
@section('step')
    {{-- RC-3 (VOL-3): the total branches on the pending's kind
         (the controller's $stepTotal): enrollment is 2 of 3
         (share is Step 3); a friend claim is 2 of 2 — its Step 1
         is the claim form and the visit page after this screen
         is the completion, not a step. --}}
    <span class="type-caption text-text-2">Step 2 of {{ $stepTotal }}</span>
@endsection

@section('content')
    {{-- R3, the code screen (spec Section 3 Step 4, inventory
         REF-VERIFY): the masked number, six boxes, resend on its
         own cadence, and the wrong-number escape. The wrong-code
         message is wired to the boxes with aria-describedby and
         announced (Section 6.6). --}}
    <h1 class="type-display-xs">Enter the verification code we sent to {{ $maskedPhone }}.</h1>

    @if (($pendingKind ?? null) === 'coupon')
        {{-- ID-11 [COUP-22]: the coupon path's verify delta
             line, replacing any referral-context line. --}}
        <p class="type-body mt-2 text-text-2">We will text you a code to unlock your coupon. This keeps it one per person.</p>
    @endif

    @if ($channel === 'call')
        <p class="status status-info mt-3">That number cannot receive text messages, so we are calling it with your code instead.</p>
    @endif

    @if ($notice)
        <p class="status status-success mt-3" role="status">{{ $notice }}</p>
    @endif

    <form method="POST" action="{{ route('refer.verify.check') }}" class="mt-5" id="verify-form" x-data data-loading>
        @csrf
        <input type="hidden" name="device_signal" data-device-signal>

        <div class="field">
            <span class="form-label" id="code_label">6-digit code</span>
            {{-- Six boxes, auto-advancing and paste-friendly
                 (REF-VER-01). JS composes them into the code field;
                 without JS the server assembles the boxes itself. --}}
            <div class="flex gap-2 {{ $error ? 'otp-group-error' : '' }}" role="group" aria-labelledby="code_label" @if ($error) aria-describedby="verify-error" @endif data-otp>
                @for ($i = 1; $i <= 6; $i++)
                    <input class="otp-box flex-1 text-center" name="code_{{ $i }}" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" data-otp-box @if ($i === 1) autocomplete="one-time-code" autofocus @endif aria-label="Digit {{ $i }}" aria-invalid="{{ $error ? 'true' : 'false' }}">
                @endfor
            </div>
            <input type="hidden" name="code" id="code" data-otp-value>
            @if ($error) <p class="form-error" id="verify-error" role="alert">{{ $error }}</p> @endif
        </div>
    </form>

    <div class="mt-4 flex items-center justify-between">
        <form method="POST" action="{{ route('refer.verify.resend') }}" class="flex items-center gap-2" data-loading>
            @csrf
            <span class="type-caption text-text-2">Did not get a code?</span>
            {{-- RC-6 (VOL-3): inside the resend cooldown the
                 button renders disabled with the real remaining
                 wait stated below (the verify-email screen's
                 disabled-with-wait pattern), because a tap in
                 that state sends nothing. The caption stands
                 down while a notice is on screen: a resend tap
                 from a stale render flashes the same wait
                 sentence as the notice, so the wait is never
                 stated twice. --}}
            @if ($resendWaitSeconds > 0)
                <button type="submit" class="btn btn-ghost btn-sm" disabled>Resend code</button>
            @else
                <button type="submit" class="btn btn-ghost btn-sm">Resend code</button>
            @endif
        </form>

        <form method="POST" action="{{ route('refer.verify.change') }}" data-loading>
            @csrf
            <button type="submit" class="btn btn-ghost btn-sm">Use a different number</button>
        </form>
    </div>

    @if ($resendWaitSeconds > 0 && ! $notice)
        <p class="type-caption mt-2 text-text-2">{{ $resendWaitMessage }}</p>
    @endif
@endsection

@section('action')
    <button type="submit" form="verify-form" class="btn btn-flow btn-primary w-full">Verify</button>
@endsection
