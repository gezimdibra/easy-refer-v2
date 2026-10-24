@extends('layouts.marketing')

@section('title', 'Blog: Easy Refer')

@section('meta_description', 'Notes from Easy Refer on referral marketing for small businesses.')

@section('content')
    <section class="shell section-pad">
        <div class="max-w-[640px]">
            <h1 class="type-title-1 text-text-1">Blog</h1>
            {{-- S-13 (release 08b): the intro states the content
                 instead of announcing the page. --}}
            <p class="mt-4 text-lg text-text-2">You will find helpful tips, success stories, and insights into how referral programs can boost small business growth.</p>

            @if ($posts->isEmpty())
                <p class="mt-10 text-base text-text-2">Posts are on the way. Check back soon.</p>
            @else
                <ul class="mt-10 space-y-6">
                    @foreach ($posts as $post)
                        <li>
                            <h2 class="type-title-3">
                                <a class="text-brand-700 hover:underline" href="{{ route('blog.post', ['slug' => $post->slug]) }}">{{ $post->title }}</a>
                            </h2>
                            <p class="mt-1 text-sm text-text-3">{{ $post->published_at->format('M j, Y') }}</p>
                            <p class="mt-2 text-base text-text-2">{{ $post->excerpt }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    {{-- DG-10 (release 08b): with zero posts the page no longer
         dead-ends on the empty-state line; the starter kit closes
         the page with an action, the same closing block the other
         marketing pages carry. --}}
    @include('partials.starter-kit', ['source' => 'blog'])
@endsection
