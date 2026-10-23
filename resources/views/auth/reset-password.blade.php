@extends('layouts.app')

@section('title', 'Set a new password')

@section('content')
    <h1>Set a new password</h1>

    <form method="POST" action="{{ route('password.store') }}">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email', $request->email) }}" required autofocus>

        <label for="password">New password</label>
        <input id="password" name="password" type="password" required autocomplete="new-password">
        <p>At least 12 characters.</p>

        <label for="password_confirmation">Confirm new password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">

        <p><button type="submit">Reset password</button></p>
    </form>
@endsection
