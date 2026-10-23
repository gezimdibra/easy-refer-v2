@extends('layouts.auth')

@section('title', 'Unlock your account')

@section('content')
    <h1 class="text-[32px] leading-[38px] font-bold text-text-1">Unlock your account</h1>
    <p>Sign-in to your account was temporarily locked after repeated failed attempts. Pressing the button below clears that lock only; you will still need your password to sign in.</p>

    <form method="POST" action="{{ route('account.unlock') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <button type="submit">Unlock my account</button>
    </form>
@endsection
