@extends('layouts.marketing')

@section('title', 'Too many requests | Easy Refer')

@section('meta_description', 'You have sent a lot of requests to Easy Refer in a short time. Please wait a moment and try again.')

@section('content')
    <section class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <div class="max-w-[720px]">
            <p class="tile-label">Error 429</p>
            <h1 class="mt-2 text-[32px] leading-[38px] font-bold text-text-1">Too many requests</h1>
            <p class="mt-4 text-lg text-text-2">You have sent a lot of requests in a short time, so we need you to slow down for a moment. Wait a minute, then try again.</p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a class="btn btn-primary" href="{{ route('home') }}">Go to the home page</a>
            </div>
        </div>
    </section>
@endsection
