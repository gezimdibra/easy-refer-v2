@extends('layouts.marketing')

@section('title', 'Something went wrong | Easy Refer')

@section('meta_description', 'Something went wrong on Easy Refer. Please try again in a few minutes.')

@section('content')
    <section class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <div class="max-w-[720px]">
            <p class="tile-label">Error 500</p>
            <h1 class="mt-2 text-[32px] leading-[38px] font-bold text-text-1">Something went wrong</h1>
            <p class="mt-4 text-lg text-text-2">Something went wrong on our side while loading this page. Please try again in a few minutes.</p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a class="btn btn-primary" href="{{ route('home') }}">Go to the home page</a>
            </div>
        </div>
    </section>
@endsection
