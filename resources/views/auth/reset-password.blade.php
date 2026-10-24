@extends('layouts.auth')

@section('title', 'Set a new password')

@section('content')
    <h1 class="type-display-xs text-text-1">Set a new password</h1>

    <form method="POST" action="{{ route('password.store') }}" class="mt-8">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="field"><label class="form-label" for="email">Email</label>
        <input class="form-input" id="email" name="email" type="email" value="{{ old('email', $request->email) }}" required autofocus autocomplete="email"></div>

        <x-password-input name="password" label="New password" autocomplete="new-password" required help="At least 12 characters." />

        <x-password-input name="password_confirmation" label="Confirm new password" autocomplete="new-password" required />

        <p class="mt-6"><button type="submit" class="btn btn-primary h-12 w-full">Reset password</button></p>
    </form>
@endsection
