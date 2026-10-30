@extends('layouts.demo')

@section('title', 'Try it live | Easy Refer')
@section('flow_label', 'Demo')

@section('banner')
    @include('demo._banner', ['business' => $business, 'exitStep' => null])
@endsection

@section('content')
    {{-- S0, the start card (Section 3): rendered on page load
         with NO session behind it. The tap creates the session
         and fires demo_started. --}}
    <p class="type-title-3 text-text-1">@include('demo._name', ['business' => $business])</p>

    @if ($business->friend_reward_text)
        <h1 class="type-display-xs mt-3">{{ $business->friendRewardHeadline() }}</h1>
    @else
        <h1 class="type-display-xs mt-3">A welcome reward for new customers</h1>
    @endif

    <p class="mt-3 text-text-2">Be the customer for a minute. Tap through the flow your customers use, step by step.</p>

    @if ($refusal ?? null)
        <p class="status status-error mt-3" role="status">{{ $refusal }}</p>
    @endif

    <form method="POST" action="{{ route('demo.start') }}" id="demo-start-form" class="mt-5">
        @csrf
        <input type="hidden" name="entry_point" value="block">
        <button type="submit" class="btn btn-primary w-full">Try it live</button>
    </form>
@endsection
