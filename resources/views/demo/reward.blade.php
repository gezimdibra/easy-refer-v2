@extends('layouts.demo')

@section('title', 'Your reward | Easy Refer')
@section('flow_label', 'Redemption')

@section('banner')
    @include('demo._banner', ['business' => $business, 'exitStep' => 'reward'])
@endsection

@section('content')
    {{-- S4, reward unlocked (the aha): the real token screen's
         shape (REF-CLAIM-01 family). A real single-use demo
         token exists behind the QR; the under-QR line is the
         device layer that survives a tight crop of the card. --}}
    <p class="type-title-3 text-text-1">@include('demo._name', ['business' => $business])</p>

    @if ($business->friend_reward_text)
        <h1 class="type-display-xs mt-4">{{ $business->friendRewardHeadline() }}</h1>
    @else
        <h1 class="type-display-xs mt-4">Show this at @include('demo._name', ['business' => $business])</h1>
    @endif

    <p class="mt-3 font-semibold text-text-1">Show this code at the counter</p>

    <div class="mt-5 text-center">
        <span class="qr-stage"><img src="{{ $qrUrl }}" alt="Your redemption QR code" width="248" height="248"></span>
        <p class="type-caption mt-2 text-text-2">Demo reward. Not valid at any real shop.</p>
    </div>

    <p class="mt-4 text-text-2">Yours is ready right away. Show this code at the counter and the staff member marks it used on their own phone.</p>
@endsection

@section('action')
    <a class="btn btn-flow btn-primary w-full" href="{{ $counterUrl }}">See what happens at the counter</a>
@endsection
