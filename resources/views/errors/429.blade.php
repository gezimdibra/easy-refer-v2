@extends('layouts.marketing')

@section('title', 'Too many requests | Easy Refer')

@section('meta_description', 'You have sent a lot of requests to Easy Refer in a short time. Please wait a moment and try again.')

@section('content')
    <section class="shell section-pad">
        <div class="mx-auto max-w-[640px] text-center">
            <p class="type-eyebrow text-link">Error 429</p>
            <h1 class="type-display-sm mt-3 text-text-1">Too many requests</h1>
            <p class="type-lede mt-4 text-text-2">You have sent a lot of requests in a short time, so we need you to slow down for a moment. Wait a minute, then try again.</p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a class="btn btn-primary" href="{{ route('home') }}">Go to the home page</a>
                <a class="btn btn-secondary" href="{{ route('login') }}">Sign in</a>
            </div>
        </div>
    </section>
@endsection
