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

            {{-- B1 (M-6 blog week 1, Amendment 73, BLOG-03 to
                 BLOG-06): the publication-gate call to action. It
                 lives OUTSIDE the body (bodies render as escaped
                 plain paragraphs and can carry no links), under the
                 body and above the back link. Demo first, then start
                 free, per the design pass. Both links carry the
                 per-post UTM set (utm_source=blog, utm_medium=
                 content, utm_campaign=blog-week1, utm_content= the
                 post slug, built from the post record, never
                 hardcoded) so AttributionService attributes a signup
                 or demo start to this post. The offer line renders
                 its free count from config like the FAQ pricing
                 answers, so it can never drift from the plan, and
                 it asserts nothing state-dependent: it is true in
                 every state where this page renders. --}}
            <div class="mt-10 border-t border-line pt-8">
                <h2 class="type-title-3 text-text-1">Ready to bring customers in?</h2>
                <p class="mt-3 text-base text-text-2">Your first {{ (int) config('billing.free_visits_at_signup') }} visits that count are free, with no monthly fee and no setup fee. You pay only when a referred friend walks in and uses their reward.</p>
                <p class="mt-6 flex flex-wrap gap-3">
                    <a class="btn btn-secondary" href="{{ route('demo.show', ['utm_source' => 'blog', 'utm_medium' => 'content', 'utm_campaign' => 'blog-week1', 'utm_content' => $post->slug]) }}">Try the demo</a>
                    <a class="btn btn-primary" href="{{ route('register', ['utm_source' => 'blog', 'utm_medium' => 'content', 'utm_campaign' => 'blog-week1', 'utm_content' => $post->slug]) }}">Start free</a>
                </p>
            </div>

            <p class="mt-10"><a class="font-semibold text-link underline" href="{{ route('blog') }}">Back to the blog</a></p>
        </article>
    </section>
@endsection
