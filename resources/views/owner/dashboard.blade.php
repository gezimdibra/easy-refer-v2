@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h1>{{ $business->name }}</h1>

    @if (! $user->hasVerifiedEmail())
        <div class="gate">
            <p><strong>Verify your email address.</strong> Your public QR code and billing stay off until the email on this account is verified.</p>
            <p><a href="{{ route('verification.notice') }}">Verify your email</a></p>
        </div>
    @endif

    <h2>Business profile</h2>
    <dl>
        <dt>Business name</dt>
        <dd>{{ $business->name }}</dd>

        <dt>Contact email</dt>
        <dd>{{ $business->contact_email }}</dd>

        <dt>Contact phone</dt>
        <dd>{{ $business->contact_phone_e164 }}</dd>

        <dt>Address</dt>
        <dd>{{ $business->street }}, {{ $business->city }}, {{ $business->state }} {{ $business->postal_code }}, {{ $business->country }}</dd>

        <dt>Signed in as</dt>
        <dd>{{ $user->name }} ({{ $user->email }})</dd>
    </dl>
@endsection
