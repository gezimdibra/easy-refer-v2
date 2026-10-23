@extends('layouts.marketing')

@section('title', $post->title.' | Easy Refer')

@section('meta_description', $post->excerpt)

@section('content')
    <section class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <article class="max-w-[640px]">
            <h1 class="text-[32px] leading-[38px] font-bold text-text-1">{{ $post->title }}</h1>
            <p class="mt-2 text-sm text-text-3">{{ $post->published_at->format('M j, Y') }}</p>

            @foreach (preg_split('/\n\s*\n/', trim($post->body)) as $paragraph)
                <p class="mt-4 text-base text-text-2">{{ $paragraph }}</p>
            @endforeach

            <p class="mt-10"><a class="font-semibold text-brand-700 underline" href="{{ route('blog') }}">Back to the blog</a></p>
        </article>
    </section>
@endsection
