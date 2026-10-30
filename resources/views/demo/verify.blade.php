@extends('layouts.demo')

@section('title', 'Enter your code | Easy Refer')
@section('flow_label', 'Verification')

@section('banner')
    @include('demo._banner', ['business' => $business, 'exitStep' => 'verify'])
@endsection

@section('content')
    {{-- S3, verification (Section 4): the real screen's shape
         (six boxes, Verify) with the honesty line and the code
         card in place of the sent-code claim. The shipped
         REF-VER-01 string never renders here: nothing was
         sent, and state-truth binds the demo exactly as it
         binds production copy. --}}
    <h1 class="type-display-xs">Enter the 6-digit code</h1>

    @if ($exhausted)
        <p class="status status-error mt-3" role="alert">That was the last try for this code. The code is no longer good.</p>

        <div class="mt-5 flex flex-col gap-3">
            <form method="POST" action="{{ route('demo.verify.new') }}">
                @csrf
                <button type="submit" class="btn btn-primary w-full">Show a new code</button>
            </form>
            <form method="POST" action="{{ route('demo.start') }}">
                @csrf
                <input type="hidden" name="entry_point" value="block">
                <button type="submit" class="btn btn-secondary w-full">Start the demo over</button>
            </form>
        </div>
    @else
        <p class="mt-3 text-text-2">In real life, this code arrives by text. In the demo, it's right here:</p>

        <div class="demo-code-card mt-4">
            <span class="demo-chip-solid" style="position: absolute; top: 10px; right: 10px;">DEMO</span>
            <p class="demo-code-value">{{ $code }}</p>
            <p class="type-caption mt-1 text-text-2">Demo code for {{ $phoneDisplay }}</p>
        </div>

        @if ($notice)
            <p class="status status-success mt-3" role="status">{{ $notice }}</p>
        @endif

        <form method="POST" action="{{ route('demo.verify.check') }}" class="mt-5" id="demo-verify-form" data-loading>
            @csrf
            <div class="field">
                <span class="form-label" id="demo_code_label">6-digit code</span>
                <div class="flex gap-2 {{ $error ? 'otp-group-error' : '' }}" role="group" aria-labelledby="demo_code_label" @if ($error) aria-describedby="demo-verify-error" @endif data-otp>
                    @for ($i = 1; $i <= 6; $i++)
                        <input class="otp-box flex-1 text-center" name="code_{{ $i }}" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" data-otp-box @if ($i === 1) autocomplete="one-time-code" autofocus @endif aria-label="Digit {{ $i }}" aria-invalid="{{ $error ? 'true' : 'false' }}">
                    @endfor
                </div>
                <input type="hidden" name="code" id="code" data-otp-value>
                @if ($error) <p class="form-error" id="demo-verify-error" role="alert">{{ $error }}</p> @endif
            </div>
        </form>

        <div class="mt-4 flex items-center justify-between">
            <form method="POST" action="{{ route('demo.verify.new') }}" data-loading>
                @csrf
                <button type="submit" class="btn btn-ghost btn-sm">Show a new code</button>
            </form>

            <form method="POST" action="{{ route('demo.verify.change') }}" data-loading>
                @csrf
                <button type="submit" class="btn btn-ghost btn-sm">Use a different number</button>
            </form>
        </div>
    @endif
@endsection

@if (! $exhausted)
    @section('action')
        <button type="submit" form="demo-verify-form" class="btn btn-flow btn-primary w-full">Verify</button>
    @endsection
@endif
