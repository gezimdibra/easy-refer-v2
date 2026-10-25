@extends('layouts.marketing')

@section('title', 'Your session expired | Easy Refer')

@section('meta_description', 'Your Easy Refer session expired. Please try again.')

@section('content')
    <section class="shell section-pad">
        <div class="mx-auto max-w-[640px] text-center">
            <p class="type-eyebrow text-link">Error 419</p>
            <h1 class="type-display-sm mt-3 text-text-1">Your session expired</h1>
            <p class="type-lede mt-4 text-text-2">For your security, this form expired because the page was open too long. Please try again.</p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a class="btn btn-primary" href="{{ route('home') }}">Go to the home page</a>
                <a class="btn btn-secondary" href="{{ route('login') }}">Sign in</a>
            </div>
        </div>
    </section>
@endsection
