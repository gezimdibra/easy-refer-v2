@extends('layouts.demo')

@section('title', 'Confirm redemption | Easy Refer')
@section('flow_label', 'Counter')

@section('banner')
    @include('demo._banner', ['business' => $business, 'exitStep' => 'counter'])
@endsection

@section('content')
    {{-- S5, the counter moment (Section 6): the staff side of
         the prospect's own token: the real redemption
         confirmation surface's shape, the real Mark as
         Redeemed button, the real nonce discipline, executed
         by the real redemption service on demo rows only. --}}
    <p class="text-text-2">Your staff see this on their own phone when they scan the customer's code.</p>

    <x-state-band state="ready_to_redeem" />

    <h1 class="type-display-xs mt-3">{{ $business->friend_reward_text ?? 'First visit reward' }}</h1>

    <p class="mt-3 font-medium text-text-1" style="font-size: 22px; line-height: 28px">For {{ $friend->first_name }}</p>

    <div class="well mt-4">
        <p class="flex items-baseline justify-between gap-3">
            <span class="type-caption text-text-2">Business</span>
            <span class="font-medium text-text-1">@include('demo._name', ['business' => $business])</span>
        </p>
    </div>

    <p class="type-caption mt-3 text-center text-text-2">Demo reward. Not valid at any real shop.</p>

    <p class="mt-3 text-text-2">One real-shop difference: if a friend tries to use a reward less than 25 minutes after the customer who shared it joined, the counter screen refuses for now and names the time the reward opens. Staff try the same code again after that time. In this demo the counter step runs right away so you can see the whole flow in a minute.</p>

    @if ($error)
        <p class="status status-error mt-3" role="status">{{ $error }}</p>
    @endif
@endsection

@section('action')
    <form method="POST" action="{{ route('demo.counter.confirm', ['token' => $token]) }}">
        @csrf
        <input type="hidden" name="nonce" value="{{ $nonce }}">
        <button type="submit" class="btn btn-counter btn-primary w-full">Mark as Redeemed</button>
    </form>
@endsection
