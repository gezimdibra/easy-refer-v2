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

    <h2>Two-factor authentication</h2>
    <p>Two-factor authentication is not enabled on your account.</p>
@endsection
