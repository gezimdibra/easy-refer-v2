@extends('layouts.marketing')

@section('title', 'No access to this page | Easy Refer')

@section('meta_description', 'This Easy Refer page is only available to people with the right access for it.')

@section('content')
    <section class="shell section-pad">
        <div class="mx-auto max-w-[640px] text-center">
            <p class="type-eyebrow text-link">Error 403</p>
            <h1 class="type-display-sm mt-3 text-text-1">You do not have access to this page</h1>
            <p class="type-lede mt-4 text-text-2">This page is only available to people with the right access for it.</p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a class="btn btn-primary" href="{{ route('home') }}">Go to the home page</a>
                <a class="btn btn-secondary" href="{{ route('login') }}">Sign in</a>
            </div>
        </div>
    </section>
@endsection
