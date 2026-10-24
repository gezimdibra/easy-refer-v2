@extends('layouts.auth')

@section('title', 'Verify your email')

@section('content')
    <h1 class="type-display-xs text-text-1">Verify your email address</h1>
    <p class="type-body mt-2 text-text-2">We sent a verification link to your email address. Open it to verify your account. Until your email is verified, your public QR code and billing stay off.</p>

    @if (session('status') === 'verification-link-sent')
        <p class="status">A new verification link is on its way to your email address.</p>
    @endif

    <form method="POST" action="{{ route('verification.send') }}" class="mt-6" data-loading>
        @csrf
        <button type="submit" class="btn btn-secondary h-12 w-full">Resend the verification email</button>
    </form>
@endsection
