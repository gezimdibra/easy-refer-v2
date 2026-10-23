@extends('layouts.app')

@section('title', 'Account security')

@section('content')
    <h1>Account security</h1>

    <h2>Change password</h2>
    <form method="POST" action="{{ route('security.password') }}">
        @csrf
        @method('PUT')

        <label for="current_password">Current password</label>
        <input id="current_password" name="current_password" type="password" required autocomplete="current-password">

        <label for="password">New password</label>
        <input id="password" name="password" type="password" required autocomplete="new-password">
        <p>At least 12 characters.</p>

        <label for="password_confirmation">Confirm new password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">

        <p><button type="submit">Update password</button></p>
    </form>

    <h2>Active sessions</h2>
    @if ($sessions->isEmpty())
        <p>No stored sessions were found. Sessions appear here once the database session store is in use.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>IP address</th>
                    <th>Device</th>
                    <th>Last active</th>
                    <th></th>
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
        </table>
    @endif

    <form method="POST" action="{{ route('security.sign-out-others') }}">
        @csrf
        <button type="submit">Sign out other devices</button>
    </form>

    <form method="POST" action="{{ route('security.sign-out-everywhere') }}">
        @csrf
        <button type="submit">Sign out everywhere</button>
    </form>

    <h2>Change email</h2>
    <p>Your current email address is {{ $user->email }}. Changing it sends a verification link to the new address, and your public QR code and billing stay off until that link is used. A notice also goes to the old address.</p>
    <form method="POST" action="{{ route('security.email') }}">
        @csrf

        <label for="new_email">New email address</label>
        <input id="new_email" name="new_email" type="email" required autocomplete="email">

        <p><button type="submit">Change email</button></p>
    </form>

    <h2>Two-factor authentication</h2>
    @if (session('two_factor_recovery_codes'))
        <div class="gate">
            <p><strong>Your recovery codes.</strong> Save these somewhere safe. Each one works once, in place of an authenticator code, and they will not be shown again.</p>
            <ul>
                @foreach (session('two_factor_recovery_codes') as $recoveryCode)
                    <li><code>{{ $recoveryCode }}</code></li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($user->hasTwoFactorEnabled())
        <p>Two-factor authentication is on for your account. Signing in asks for a code from your authenticator app.</p>
        <form method="POST" action="{{ route('security.two-factor.disable') }}">
            @csrf

            <label for="two_factor_current_password">Current password (required to turn this off)</label>
            <input id="two_factor_current_password" name="current_password" type="password" required autocomplete="current-password">

            <p><button type="submit">Turn off two-factor authentication</button></p>
        </form>
    @elseif ($user->hasPendingTwoFactorEnrollment())
        <p>You started setup but have not finished it. Two-factor authentication is not on yet.</p>
        <p><a href="{{ route('security.two-factor.confirm') }}">Finish setting up two-factor authentication</a></p>
        <form method="POST" action="{{ route('security.two-factor.start') }}">
            @csrf

            <label for="two_factor_restart_password">Current password (to start over with a fresh secret)</label>
            <input id="two_factor_restart_password" name="current_password" type="password" required autocomplete="current-password">

            <p><button type="submit">Start over</button></p>
        </form>
    @else
        <p>Two-factor authentication is not enabled on your account. Turn it on and signing in will also ask for a code from an authenticator app.</p>
        <form method="POST" action="{{ route('security.two-factor.start') }}">
            @csrf

            <label for="two_factor_current_password">Current password (required to set this up)</label>
            <input id="two_factor_current_password" name="current_password" type="password" required autocomplete="current-password">

            <p><button type="submit">Set up two-factor authentication</button></p>
        </form>
    @endif
@endsection
