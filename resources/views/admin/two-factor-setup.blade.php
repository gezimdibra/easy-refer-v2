@extends('layouts.admin')

@section('title', 'Set up two-factor authentication')

@section('notices_summary_only', '1')

@section('content')
    <div class="card mx-auto max-w-[560px]">
        <h1 class="text-[28px] font-bold">Set up two-factor authentication</h1>
        <p class="mt-1 text-text-2">Platform accounts must use two-factor authentication. Finish setup to reach the console.</p>

        @if (session('two_factor_recovery_codes'))
            <div class="gate mt-4">
                <p><strong>Your recovery codes.</strong> Save these somewhere safe. Each one works once, in place of an authenticator code, and they will not be shown again.</p>
                <ul>
                    @foreach (session('two_factor_recovery_codes') as $recoveryCode)
                        <li><code>{{ $recoveryCode }}</code></li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($provisioningUri !== null)
            <p class="mt-4">Add this account to your authenticator app:</p>
            <p class="mt-2 break-all rounded-md border border-line p-3"><code>{{ $provisioningUri }}</code></p>

            <form method="POST" action="{{ route('admin.two-factor.confirm') }}" class="mt-4">
                @csrf

                <label for="code">Code from your authenticator app</label>
                <input id="code" name="code" type="text" inputmode="numeric" required autocomplete="one-time-code"
                    aria-invalid="{{ $errors->has('code') ? 'true' : 'false' }}"
                    @if ($errors->has('code')) aria-describedby="code-error" @endif>
                @error('code') <p class="form-error" id="code-error">{{ $message }}</p> @enderror

                <label for="current_password">Current password</label>
                <input id="current_password" name="current_password" type="password" required autocomplete="current-password">

                <p class="mt-4"><button type="submit" class="btn btn-primary">Confirm and turn on</button></p>
            </form>
        @else
            <form method="POST" action="{{ route('admin.two-factor.start') }}" class="mt-4">
                @csrf

                <label for="current_password">Current password (required to set this up)</label>
                <input id="current_password" name="current_password" type="password" required autocomplete="current-password">

                <p class="mt-4"><button type="submit" class="btn btn-primary">Start setup</button></p>
            </form>
        @endif
    </div>
@endsection
