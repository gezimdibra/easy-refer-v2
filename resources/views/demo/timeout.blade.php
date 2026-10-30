@extends('layouts.demo')

@section('title', 'Demo timed out | Easy Refer')
@section('flow_label', 'Demo')

@section('banner')
    @if ($business ?? null)
        @include('demo._banner', ['business' => $business, 'exitStep' => null])
    @endif
@endsection

@section('content')
    {{-- The expired state (Section 11.1): the session and
         everything it held are gone, and the screen says
         exactly that. Inert links from a dead session land
         here, never on a live step. --}}
    <h1 class="type-display-xs">This demo timed out. Nothing from it was kept.</h1>
    <p class="mt-3 text-text-2">Everything you typed or created in this demo is deleted within a few minutes after it times out, whether or not you come back to this screen.</p>

    <form method="POST" action="{{ route('demo.start') }}" class="mt-5">
        @csrf
        <input type="hidden" name="entry_point" value="block">
        <button type="submit" class="btn btn-primary w-full">Start again</button>
    </form>
@endsection
