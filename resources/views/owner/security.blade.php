@extends('layouts.app')

@section('title', 'Security')

@section('notices_summary_only', '1')

@section('content')
    {{-- Security (AUTH-SEC-01), a Settings section since Sprint
         3: the same fields and endpoints the page has always had,
         presented in the design system like every other Settings
         page (cards, the closed button set, F-06 field wiring). --}}
    <h1 class="text-[28px] font-bold">Security</h1>

    @include('owner.settings.nav')

    <div class="card mt-4">
        <h2 class="text-xl font-semibold">Change password</h2>
        <form method="POST" action="{{ route('security.password') }}" class="mt-3">
            @csrf
            @method('PUT')

            <label for="current_password">Current password</label>
            <input id="current_password" name="current_password" type="password" required autocomplete="current-password"
                aria-invalid="{{ $errors->has('current_password') ? 'true' : 'false' }}"
                @if ($errors->has('current_password')) aria-describedby="current_password-error" @endif>
            @error('current_password') <p class="form-error" id="current_password-error">{{ $message }}</p> @enderror

            <label for="password">New password</label>
            <input id="password" name="password" type="password" required autocomplete="new-password"
                aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                @if ($errors->has('password')) aria-describedby="password-error" @endif>
            @error('password') <p class="form-error" id="password-error">{{ $message }}</p> @enderror
            <p class="form-help">At least 12 characters.</p>

            <label for="password_confirmation">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                aria-invalid="{{ $errors->has('password_confirmation') ? 'true' : 'false' }}"
                @if ($errors->has('password_confirmation')) aria-describedby="password_confirmation-error" @endif>
            @error('password_confirmation') <p class="form-error" id="password_confirmation-error">{{ $message }}</p> @enderror

            <p class="mt-4"><button type="submit" class="btn btn-primary">Update password</button></p>
        </form>
    </div>

    <div class="card mt-4">
        <h2 class="text-xl font-semibold">Active sessions</h2>
        @if ($sessions->isEmpty())
            <p class="mt-1 text-text-2">No other devices are signed in.</p>
        @else
            <table class="mt-2 w-full text-left text-base">
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="py-2 pr-3 font-semibold">IP address</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Device</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Last active</th>
                        <th scope="col" class="py-2 font-semibold"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sessions as $session)
                        <tr class="border-b border-line">
                            <td class="py-2 pr-3">{{ $session['ip_address'] }}</td>
                            <td class="py-2 pr-3">{{ $session['user_agent'] }}</td>
                            <td class="py-2 pr-3">{{ $session['last_active'] }}</td>
                            <td class="py-2">{{ $session['is_current'] ? 'This device' : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="mt-4 flex flex-wrap gap-2">
            <form method="POST" action="{{ route('security.sign-out-others') }}">
                @csrf
                <button type="submit" class="btn btn-secondary">Sign out all other devices</button>
            </form>

            <form method="POST" action="{{ route('security.sign-out-everywhere') }}">
                @csrf
                <button type="submit" class="btn btn-secondary">Sign out everywhere</button>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <h2 class="text-xl font-semibold">Change email</h2>
        <p class="mt-1 text-text-2">Your current email address is {{ $user->email }}. Changing it sends a verification link to the new address, and your public QR code and billing stay off until that link is used. A notice also goes to the old address.</p>
        <form method="POST" action="{{ route('security.email') }}" class="mt-3">
            @csrf

            <label for="new_email">New email address</label>
            <input id="new_email" name="new_email" type="email" required autocomplete="email"
                aria-invalid="{{ $errors->has('new_email') ? 'true' : 'false' }}"
                @if ($errors->has('new_email')) aria-describedby="new_email-error" @endif>
            @error('new_email') <p class="form-error" id="new_email-error">{{ $message }}</p> @enderror

            <p class="mt-4"><button type="submit" class="btn btn-primary">Change email</button></p>
        </form>
    </div>

    <div class="card mt-4">
        <h2 class="text-xl font-semibold">Two-factor authentication</h2>
        @if (session('two_factor_recovery_codes'))
            <div class="gate mt-3">
                <p><strong>Your recovery codes.</strong> Save these somewhere safe. Each one works once, in place of an authenticator code, and they will not be shown again.</p>
                <ul>
                    @foreach (session('two_factor_recovery_codes') as $recoveryCode)
                        <li><code>{{ $recoveryCode }}</code></li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($user->hasTwoFactorEnabled())
            <p class="mt-1 text-text-2">Two-factor authentication is on for your account. Signing in asks for a code from your authenticator app.</p>
            <form method="POST" action="{{ route('security.two-factor.disable') }}" class="mt-3">
                @csrf

                <label for="two_factor_current_password">Current password (required to turn this off)</label>
                <input id="two_factor_current_password" name="current_password" type="password" required autocomplete="current-password"
                    aria-invalid="{{ $errors->has('current_password') ? 'true' : 'false' }}"
                    @if ($errors->has('current_password')) aria-describedby="two_factor_current_password-error" @endif>
                @error('current_password') <p class="form-error" id="two_factor_current_password-error">{{ $message }}</p> @enderror

                <p class="mt-4"><button type="submit" class="btn btn-danger-ghost">Turn off two-factor authentication</button></p>
            </form>
        @elseif ($user->hasPendingTwoFactorEnrollment())
            <p class="mt-1 text-text-2">You started setup but have not finished it. Two-factor authentication is not on yet.</p>
            <p class="mt-2"><a class="font-semibold text-brand-700 hover:underline" href="{{ route('security.two-factor.confirm') }}">Finish setting up two-factor authentication</a></p>
            <form method="POST" action="{{ route('security.two-factor.start') }}" class="mt-3">
                @csrf

                <label for="two_factor_restart_password">Current password (to start over with a fresh secret)</label>
                <input id="two_factor_restart_password" name="current_password" type="password" required autocomplete="current-password"
                    aria-invalid="{{ $errors->has('current_password') ? 'true' : 'false' }}"
                    @if ($errors->has('current_password')) aria-describedby="two_factor_restart_password-error" @endif>
                @error('current_password') <p class="form-error" id="two_factor_restart_password-error">{{ $message }}</p> @enderror

                <p class="mt-4"><button type="submit" class="btn btn-secondary">Start over</button></p>
            </form>
        @else
            <p class="mt-1 text-text-2">Two-factor authentication is not enabled on your account. Turn it on and signing in will also ask for a code from an authenticator app.</p>
            <form method="POST" action="{{ route('security.two-factor.start') }}" class="mt-3">
                @csrf

                <label for="two_factor_current_password">Current password (required to set this up)</label>
                <input id="two_factor_current_password" name="current_password" type="password" required autocomplete="current-password"
                    aria-invalid="{{ $errors->has('current_password') ? 'true' : 'false' }}"
                    @if ($errors->has('current_password')) aria-describedby="two_factor_current_password-error" @endif>
                @error('current_password') <p class="form-error" id="two_factor_current_password-error">{{ $message }}</p> @enderror

                <p class="mt-4"><button type="submit" class="btn btn-primary">Set up two-factor authentication</button></p>
            </form>
        @endif
    </div>
@endsection
