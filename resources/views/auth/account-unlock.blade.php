@extends('layouts.auth')

@section('title', 'Unlock your account')

@section('content')
    <h1 class="type-display-xs text-text-1">Unlock your account</h1>
    <p class="type-body mt-2 text-text-2">Sign-in to your account was temporarily locked after repeated failed attempts. Pressing the button below clears that lock only; you will still need your password to sign in.</p>

    <form method="POST" action="{{ route('account.unlock') }}" class="mt-6" data-loading>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <button type="submit" class="btn btn-primary h-12 w-full">Unlock my account</button>
    </form>
@endsection
