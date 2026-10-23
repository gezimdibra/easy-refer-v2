@extends('layouts.marketing')

@section('title', 'Blog: Easy Refer')

@section('meta_description', 'Notes from Easy Refer on referral marketing for small businesses.')

@section('content')
    <section class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <div class="max-w-[640px]">
            <h1 class="text-[32px] leading-[38px] font-bold text-text-1">Blog</h1>
            <p class="mt-4 text-lg text-text-2">Welcome to the Easy Refer blog. Here you will find helpful tips, success stories, and insights into how referral programs can boost small business growth.</p>

            @if ($posts->isEmpty())
                <p class="mt-10 text-base text-text-2">Posts are on the way. Check back soon.</p>
            @else
                <ul class="mt-10 space-y-6">
                    @foreach ($posts as $post)
                        <li>
                            <h2 class="text-xl font-semibold">
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
@endsection
