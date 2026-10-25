@extends('layouts.marketing')

@section('title', $post->title.' | Easy Refer')

@section('meta_description', $post->excerpt)

@section('content')
    <section class="shell section-pad">
        <article class="max-w-[640px]">
            <h1 class="type-title-1 text-text-1">{{ $post->title }}</h1>
            <p class="mt-2 text-sm text-text-3">{{ $post->published_at->format('M j, Y') }}</p>

            @foreach (preg_split('/\n\s*\n/', trim($post->body)) as $paragraph)
                <p class="mt-4 text-base text-text-2">{{ $paragraph }}</p>
            @endforeach

            <p class="mt-10"><a class="font-semibold text-link underline" href="{{ route('blog') }}">Back to the blog</a></p>
        </article>
    </section>
@endsection
