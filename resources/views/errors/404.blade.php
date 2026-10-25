@extends('layouts.marketing')

@section('title', 'Page not found | Easy Refer')

@section('meta_description', 'The page you were looking for could not be found on Easy Refer.')

@section('content')
    <section class="shell section-pad">
        <div class="mx-auto max-w-[640px] text-center">
            <p class="type-eyebrow text-link">Error 404</p>
            <h1 class="type-display-sm mt-3 text-text-1">Page not found</h1>
            <p class="type-lede mt-4 text-text-2">We could not find the page you were looking for. The link may be out of date, or the address may have been typed incorrectly.</p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a class="btn btn-primary" href="{{ route('home') }}">Go to the home page</a>
                <a class="btn btn-secondary" href="{{ route('login') }}">Sign in</a>
            </div>
        </div>
    </section>
@endsection
