@extends('layouts.marketing')

@section('title', 'No access to this page | Easy Refer')

@section('meta_description', 'This Easy Refer page is only available to people with the right access for it.')

@section('content')
    <section class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <div class="max-w-[720px]">
            <p class="tile-label">Error 403</p>
            <h1 class="mt-2 text-[32px] leading-[38px] font-bold text-text-1">You do not have access to this page</h1>
            <p class="mt-4 text-lg text-text-2">This page is only available to people with the right access for it.</p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a class="btn btn-primary" href="{{ route('home') }}">Go to the home page</a>
            </div>
        </div>
    </section>
@endsection
