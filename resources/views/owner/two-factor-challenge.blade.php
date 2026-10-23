@extends('layouts.auth')

@section('title', 'Two-factor verification')

@section('content')
    <h1>Two-factor verification</h1>
    <p>Enter the six-digit code from your authenticator app. You can also use one of your recovery codes instead.</p>

    <form method="POST" action="{{ route('two-factor.challenge.store') }}">
        @csrf

        <label for="code">Code</label>
        <input id="code" name="code" type="text" autocomplete="one-time-code" required autofocus>

        <p><button type="submit">Verify and continue</button></p>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Sign out</button>
    </form>
@endsection
