@extends('emails.layout')

@section('title', 'Reset your Easy Refer password')

@section('content')
    {{-- EU-S2: the password reset mail on the custom shell, with
         the branded subject (R5-MAIL-01, PROPOSED). Body sentences
         are the framework's, verbatim; the headline is PROPOSED. --}}
    <h1 class="em-text" style="margin: 0; font-size: 24px; line-height: 30px; font-weight: 650; letter-spacing: -0.02em; color: #16130e">Reset your password</h1>
    <p class="em-text" style="margin: 16px 0 0; font-size: 16px; line-height: 26px; color: #16130e">You are receiving this email because we received a password reset request for your account.</p>
    @include('emails.partials.button', ['url' => $url, 'label' => 'Reset Password'])
    <p class="em-muted" style="margin: 24px 0 0; font-size: 14px; line-height: 22px; color: #453d31">Or open this link: <a href="{{ $url }}" class="em-a" style="color: #0d0b08; text-decoration: underline">{{ $url }}</a></p>
    <p class="em-text" style="margin: 16px 0 0; font-size: 16px; line-height: 26px; color: #16130e">{{ $expiryLine ?? 'This password reset link expires within the hour.' }}</p>
    <p class="em-text" style="margin: 16px 0 0; font-size: 16px; line-height: 26px; color: #16130e">If you did not request a password reset, no further action is required.</p>
@endsection
