@extends('layouts.demo')

@section('title', 'Try it live | Easy Refer')
@section('flow_label', 'Demo')

@section('banner')
    @include('demo._banner', ['business' => $business, 'exitStep' => null])
@endsection

@section('content')
    {{-- The resting state (Section 12.3): renders ONLY when
         demo starts are actually paused. In every other state
         this line is absent from the page. --}}
    <p class="type-title-3 text-text-1">@include('demo._name', ['business' => $business])</p>

    <h1 class="type-display-xs mt-3">The demo is resting right now. Please check back later today.</h1>
@endsection
