@extends('layouts.claimable')
{{-- The contested-claim intake (C41): nothing changes while the
     check runs (§4); the claimed business keeps running. --}}
@section('title', 'Tell us about this claim')
@section('content')
    <p data-copy="C41">{{ \App\Support\ClaimableCopy::c41() }}</p>
    <form method="POST" action="{{ route('claimable.contested.store', ['token' => $token]) }}">
        @csrf
        <label for="ct-name">Your name</label>
        <input id="ct-name" name="name" required>
        <label for="ct-phone">How to reach you</label>
        <input id="ct-phone" name="phone" type="tel" required>
        <button type="submit" class="btn">Send</button>
    </form>
@endsection
