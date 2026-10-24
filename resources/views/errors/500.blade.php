@extends('layouts.marketing')

@section('title', 'Something went wrong | Easy Refer')

@section('meta_description', 'Something went wrong on Easy Refer. Please try again in a few minutes.')

@section('content')
    <section class="shell section-pad">
        <div class="mx-auto max-w-[640px] text-center">
            <p class="type-eyebrow text-brand-700">Error 500</p>
            <h1 class="type-display-sm mt-3 text-text-1">Something went wrong</h1>
            <p class="type-lede mt-4 text-text-2">Something went wrong on our side while loading this page. Please try again in a few minutes.</p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a class="btn btn-primary" href="{{ route('home') }}">Go to the home page</a>
                <a class="btn btn-secondary" href="{{ route('login') }}">Sign in</a>
            </div>
            <p class="type-caption mt-6 text-text-2"><a class="text-text-2 underline decoration-line-strong underline-offset-[3px] hover:text-text-1" href="mailto:support@easy-refer.com">support@easy-refer.com</a></p>
        </div>
    </section>
@endsection
