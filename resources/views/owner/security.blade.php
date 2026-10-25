@extends('layouts.app')

@section('title', 'Security')

@section('notices_summary_only', '1')

@section('content')
    {{-- Security (AUTH-SEC-01), a Settings section since Sprint
         3: the same fields and endpoints the page has always had,
         presented in the design system like every other Settings
         page (cards, the closed button set, F-06 field wiring). --}}
    <h1 class="type-title-2 text-text-1">Security</h1>

    <nav aria-label="Breadcrumb" class="type-caption mt-2 text-text-2">
        <a class="text-text-2 hover:text-text-1" href="{{ route('settings') }}">Settings</a>
        <span aria-hidden="true"> / </span>
        <span aria-current="page" class="text-text-1">Security</span>
    </nav>

    <div class="mt-6 lg:flex lg:items-start lg:gap-8">
        <div class="lg:w-[220px] lg:flex-none">
            @include('owner.settings.nav')
        </div>
        <div class="mt-6 min-w-0 flex-1 lg:mt-0">

    <div class="panel mt-4 [&_input]:max-w-[560px] [&_select]:max-w-[560px]">
        <h2 class="type-title-3 text-text-1">Change password</h2>
        <form method="POST" action="{{ route('security.password') }}" class="mt-3" data-loading>
            @csrf
            @method('PUT')

            <x-password-input name="current_password" bag="password" label="Current password" autocomplete="current-password" required />

            <x-password-input name="password" bag="password" label="New password" autocomplete="new-password" required help="At least 12 characters." />

            <x-password-input name="password_confirmation" bag="password" label="Confirm new password" autocomplete="new-password" required />

            <p class="mt-4"><button type="submit" class="btn btn-primary">Update password</button></p>
        </form>
    </div>

    <div class="panel mt-4 [&_input]:max-w-[560px] [&_select]:max-w-[560px]">
        <h2 class="type-title-3 text-text-1">Active sessions</h2>
        @if ($sessions->isEmpty())
            <p class="mt-1 text-text-2">No other devices are signed in.</p>
        @else
            <div class="mt-3 overflow-x-auto"><table class="tbl">
                <thead>
                    <tr>
                        <th scope="col">IP address</th>
                        <th scope="col">Device</th>
                        <th scope="col">Last active</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sessions as $session)
                        <tr>
                            <td>{{ $session['ip_address'] }}</td>
                            <td>{{ $session['user_agent'] }}</td>
                            <td>{{ $session['last_active'] }}</td>
                            <td>{{ $session['is_current'] ? 'This device' : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif

        <div class="mt-4 flex flex-wrap gap-2">
            <form method="POST" action="{{ route('security.sign-out-others') }}" data-loading>
                @csrf
                <button type="submit" class="btn btn-secondary">Sign out all other devices</button>
            </form>

            <form method="POST" action="{{ route('security.sign-out-everywhere') }}" data-loading>
                @csrf
                <button type="submit" class="btn btn-secondary">Sign out everywhere</button>
            </form>
        </div>
    </div>

    <div class="panel mt-4 [&_input]:max-w-[560px] [&_select]:max-w-[560px]">
        <h2 class="type-title-3 text-text-1">Change email</h2>
        <p class="mt-1 text-text-2">Your current email address is {{ $user->email }}. Changing it sends a verification link to the new address, and your public QR code and billing stay off until that link is used. A notice also goes to the old address.</p>
        <form method="POST" action="{{ route('security.email') }}" class="mt-3" data-loading>
            @csrf

            <label for="new_email">New email address</label>
            <input id="new_email" name="new_email" type="email" required autocomplete="email"
                aria-invalid="{{ $errors->has('new_email') ? 'true' : 'false' }}"
                @if ($errors->has('new_email')) aria-describedby="new_email-error" @endif>
            @error('new_email') <p class="form-error" id="new_email-error">{{ $message }}</p> @enderror

            <p class="mt-4"><button type="submit" class="btn btn-primary">Change email</button></p>
        </form>
    </div>

    <div class="panel mt-4 [&_input]:max-w-[560px] [&_select]:max-w-[560px]">
        <h2 class="type-title-3 text-text-1">Two-factor authentication</h2>
        @if (session('two_factor_recovery_codes'))
            <div class="gate mt-3">
                <p><strong>Your recovery codes.</strong> Save these somewhere safe. Each one works once, in place of an authenticator code, and they will not be shown again.</p>
                <ul class="well mt-3 grid grid-cols-2 gap-2">
                    @foreach (session('two_factor_recovery_codes') as $recoveryCode)
                        <li><code class="font-mono tabular-nums">{{ $recoveryCode }}</code></li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($user->hasTwoFactorEnabled())
            <p class="mt-1 text-text-2">Two-factor authentication is on for your account. Signing in asks for a code from your authenticator app.</p>
            <form method="POST" action="{{ route('security.two-factor.disable') }}" class="mt-3" data-loading>
                @csrf

                <x-password-input name="current_password" bag="two_factor" id="two_factor_current_password" label="Current password (required to turn this off)" required />

                <p class="mt-4"><button type="submit" class="btn btn-danger-ghost">Turn off two-factor authentication</button></p>
            </form>
        @elseif ($user->hasPendingTwoFactorEnrollment())
            <p class="mt-1 text-text-2">You started setup but have not finished it. Two-factor authentication is not on yet.</p>
            <p class="mt-2"><a class="font-semibold text-link hover:underline" href="{{ route('security.two-factor.confirm') }}">Finish setting up two-factor authentication</a></p>
            <form method="POST" action="{{ route('security.two-factor.start') }}" class="mt-3" data-loading>
                @csrf

                <x-password-input name="current_password" bag="two_factor" id="two_factor_restart_password" label="Current password (to start over with a fresh secret)" required />

                <p class="mt-4"><button type="submit" class="btn btn-secondary">Start over</button></p>
            </form>
        @else
            <p class="mt-1 text-text-2">Two-factor authentication is not enabled on your account. Turn it on and signing in will also ask for a code from an authenticator app.</p>
            <form method="POST" action="{{ route('security.two-factor.start') }}" class="mt-3" data-loading>
                @csrf

                <x-password-input name="current_password" bag="two_factor" id="two_factor_current_password" label="Current password (required to set this up)" required />

                <p class="mt-4"><button type="submit" class="btn btn-primary">Set up two-factor authentication</button></p>
            </form>
        @endif
    </div>
        </div>
    </div>
@endsection
