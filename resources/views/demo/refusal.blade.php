@extends('layouts.demo')

@section('title', 'Code not active | Easy Refer')
@section('flow_label', 'Counter')

@section('banner')
    @include('demo._banner', ['business' => $business, 'exitStep' => null])
@endsection

@section('content')
    {{-- The generic not-active answer (Section 6.2): the same
         shape the real flow gives a token it cannot honour.
         It reveals nothing about the token that was presented:
         unknown, real, and spent tokens all read identically. --}}
    <h1 class="type-display-xs">This code is not active</h1>
    <p class="mt-2 text-text-2">Please ask the business for help, or check that you scanned the whole code.</p>
@endsection
