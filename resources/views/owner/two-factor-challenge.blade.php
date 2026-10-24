@extends('layouts.auth')

@section('title', 'Two-factor verification')

@section('content')
    <h1 class="type-display-xs text-text-1">Two-factor verification</h1>
    <p class="type-body mt-2 text-text-2">Enter the six-digit code from your authenticator app. You can also use one of your recovery codes instead.</p>

    <form class="mt-8" method="POST" action="{{ route('two-factor.challenge.store') }}">
        @csrf

        <label class="form-label" for="code">Code</label>
        <input class="form-input" id="code" name="code" type="text" autocomplete="one-time-code" required autofocus>

        <p class="mt-6"><button type="submit" class="btn btn-primary h-12 w-full">Verify and continue</button></p>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn btn-ghost w-full">Sign out</button>
    </form>
@endsection
