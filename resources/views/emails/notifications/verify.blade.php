@extends('emails.layout')

@section('title', 'Verify your email address')

@section('content')
    {{-- EU-S2: the framework verify mail on the custom shell.
         Body sentences and the button label are the framework's,
         verbatim; the headline is R5-MAIL-01's PROPOSED headline. --}}
    <h1 class="em-text" style="margin: 0; font-size: 24px; line-height: 30px; font-weight: 650; letter-spacing: -0.02em; color: #16130e">Verify your email address</h1>
    <p class="em-text" style="margin: 16px 0 0; font-size: 16px; line-height: 26px; color: #16130e">Please click the button below to verify your email address.</p>
    @include('emails.partials.button', ['url' => $url, 'label' => 'Verify Email Address'])
    <p class="em-muted" style="margin: 24px 0 0; font-size: 14px; line-height: 22px; color: #453d31">Or open this link: <a href="{{ $url }}" class="em-a" style="color: #0d0b08; text-decoration: underline">{{ $url }}</a></p>
    <p class="em-text" style="margin: 16px 0 0; font-size: 16px; line-height: 26px; color: #16130e">If you did not create an account, no further action is required.</p>
@endsection
