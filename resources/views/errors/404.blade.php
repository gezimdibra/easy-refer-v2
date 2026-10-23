@extends('layouts.marketing')

@section('title', 'Page not found | Easy Refer')

@section('meta_description', 'The page you were looking for could not be found on Easy Refer.')

@section('content')
    <section class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <div class="max-w-[720px]">
            <p class="tile-label">Error 404</p>
            <h1 class="mt-2 text-[32px] leading-[38px] font-bold text-text-1">Page not found</h1>
            <p class="mt-4 text-lg text-text-2">We could not find the page you were looking for. The link may be out of date, or the address may have been typed incorrectly.</p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a class="btn btn-primary" href="{{ route('home') }}">Go to the home page</a>
                <a class="btn btn-secondary" href="{{ route('login') }}">Sign in</a>
            </div>
        </div>
    </section>
@endsection
