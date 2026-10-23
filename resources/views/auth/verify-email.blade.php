@extends('layouts.auth')

@section('title', 'Verify your email')

@section('content')
    <h1 class="text-[32px] leading-[38px] font-bold text-text-1">Verify your email address</h1>
    <p>We sent a verification link to your email address. Open it to verify your account. Until your email is verified, your public QR code and billing stay off.</p>

    @if (session('status') === 'verification-link-sent')
        <p class="status">A new verification link is on its way to your email address.</p>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit">Resend the verification email</button>
    </form>
@endsection
