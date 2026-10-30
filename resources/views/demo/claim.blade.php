@extends('layouts.demo')

@section('title', 'Claim your reward | Easy Refer')
@section('flow_label', 'Claim')

@section('banner')
    @include('demo._banner', ['business' => $business, 'exitStep' => 'claim'])
@endsection

@section('content')
    {{-- S1, the claim landing (Section 3): Juniper's claim page
         for the demo offer: the shop name with its chip, the
         reward offer in the shipped claim shape, what happens
         next in one line. --}}
    <p class="type-title-3 text-text-1">@include('demo._name', ['business' => $business])</p>

    @if ($business->friend_reward_text)
        <h1 class="type-display-xs mt-3">{{ $business->friendRewardHeadline() }}</h1>
        <p class="mt-2 text-text-2">Claim {{ $business->friend_reward_text }} at @include('demo._name', ['business' => $business]).</p>
    @else
        <h1 class="type-display-xs mt-3">Claim your reward at @include('demo._name', ['business' => $business])</h1>
    @endif

    <p class="mt-3 text-text-2">Your details, a quick code, then show your reward at the counter.</p>

    <form method="POST" action="{{ route('demo.claim.next') }}" class="mt-5">
        @csrf
        <button type="submit" class="btn btn-primary w-full">Claim your reward</button>
    </form>
@endsection
