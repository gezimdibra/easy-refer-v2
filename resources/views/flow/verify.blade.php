@extends('layouts.flow')

@section('title', 'Enter your code | Easy Refer')
@section('flow_label', 'Verification')

@section('step')
    <span class="text-sm text-text-2">Step 2 of 3</span>
@endsection

@section('content')
    {{-- R3, the code screen (spec Section 3 Step 4, inventory
         REF-VERIFY): the masked number, six boxes, resend on its
         own cadence, and the wrong-number escape. The wrong-code
         message is wired to the boxes with aria-describedby and
         announced (Section 6.6). --}}
    <h1 class="text-[32px] font-bold leading-[38px]">Enter the verification code we sent to {{ $maskedPhone }}.</h1>

    @if ($channel === 'call')
        <p class="status status-info mt-3">That number cannot receive text messages, so we are calling it with your code instead.</p>
    @endif

    @if ($notice)
        <p class="status status-success mt-3" role="status">{{ $notice }}</p>
    @endif

    <form method="POST" action="{{ route('refer.verify.check') }}" class="mt-5" id="verify-form" x-data>
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
        <form method="POST" action="{{ route('refer.verify.resend') }}" class="flex items-center gap-2">
            @csrf
            <span class="text-text-2">Did not get a code?</span>
            <button type="submit" class="btn btn-ghost">Resend code</button>
        </form>

        <form method="POST" action="{{ route('refer.verify.change') }}">
            @csrf
            <button type="submit" class="btn btn-ghost">Use a different number</button>
        </form>
    </div>
@endsection

@section('action')
    <button type="submit" form="verify-form" class="btn btn-flow btn-primary w-full">Verify</button>
@endsection
