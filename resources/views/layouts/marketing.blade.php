<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- SEO and social metadata (landing-page-spec.md Section 7):
         one implementation here, per-page description overrides via
         the meta_description section. Every URL is built from the
         app URL config, so the set is correct at both mounts. --}}
    @php
        $metaTitle = trim($__env->yieldContent('title', config('app.name')));
        $metaDescription = trim($__env->yieldContent('meta_description', 'Easy Refer turns your customers into referrers with QR codes, verified visits, and rewards you only pay for when they work.'));
        $metaUrl = url()->current();
        $metaImage = asset('images/photos/og-stores.jpg');
    @endphp
    <meta name="description" content="{{ $metaDescription }}">
    <title>{{ $metaTitle }}</title>
    <link rel="canonical" href="{{ $metaUrl }}">
    <meta name="theme-color" content="#ffffff">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Easy Refer">
    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:url" content="{{ $metaUrl }}">
    <meta property="og:image" content="{{ $metaImage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="A printed Easy Refer referral sign with a QR code on the counter at Ungaro Coal Fired Pizza Cafe.">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $metaTitle }}">
    <meta name="twitter:description" content="{{ $metaDescription }}">
    <meta name="twitter:image" content="{{ $metaImage }}">
    <script type="application/ld+json">
    {
        "@@context": "https://schema.org",
        "@@graph": [
            {
                "@@type": "Organization",
                "name": "Easy Refer",
                "url": "{{ route('home') }}",
                "email": "support@easy-refer.com",
                "telephone": "+1 (833) 803-2400",
                "sameAs": [
                    "https://www.facebook.com/people/EasyRefer/61571833155688",
                    "https://www.instagram.com/easy.refer",
                    "https://www.linkedin.com/company/easyrefer",
                    "https://x.com/easy_refer"
                ],
                "contactPoint": {
                    "@@type": "ContactPoint",
                    "email": "support@easy-refer.com",
                    "telephone": "+1-833-803-2400",
                    "contactType": "customer support"
                }
            },
            {
                "@@type": "WebSite",
                "name": "Easy Refer",
                "url": "{{ route('home') }}"
            }
        ]
    }
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.analytics')
</head>
<body class="bg-surface-0 text-text-1">
    <a class="skip-link" href="#main">Skip to content</a>

    <header class="sticky top-0 z-20 border-b border-line bg-surface-0">
        <div class="mx-auto flex h-16 max-w-[1120px] items-center justify-between gap-4 px-5">
            <a href="{{ route('home') }}" class="text-xl font-bold text-text-1">Easy Refer</a>

            @if (trim($__env->yieldContent('chrome')) === 'minimal')
                {{-- Vertical landings (spec Section 2.6): the wordmark
                     and one Sign in link only; every other link is an
                     exit the advertiser paid to create. --}}
                <a href="{{ route('login') }}" class="font-semibold text-brand-700 hover:underline">Sign in</a>
            @else
                <nav aria-label="Site" class="hidden items-center gap-6 md:flex">
                    <a class="text-text-1 hover:underline" href="{{ route('home') }}#features">Features</a>
                    <a class="text-text-1 hover:underline" href="{{ route('home') }}#benefits">Benefits</a>
                    <a class="text-text-1 hover:underline" href="{{ route('home') }}#how-it-works">How It Works</a>
                    <a class="text-text-1 hover:underline" href="{{ route('home') }}#pricing">Pricing</a>
                    <a class="text-text-1 hover:underline" href="{{ route('about') }}">About Us</a>
                    <a href="{{ route('login') }}" class="btn btn-secondary">Business Portal</a>
                </nav>

                <div class="relative md:hidden" x-data="{ open: false }">
                    <button type="button" class="btn btn-secondary" aria-haspopup="true" :aria-expanded="open.toString()" @click="open = ! open">Menu</button>
                    <nav aria-label="Site menu" x-show="open" x-cloak class="absolute right-0 z-40 mt-2 w-56 rounded-lg border border-line bg-surface-0 p-3 shadow-e2">
                        <a class="block rounded-md px-3 py-2 hover:bg-surface-1" href="{{ route('home') }}#features">Features</a>
                        <a class="block rounded-md px-3 py-2 hover:bg-surface-1" href="{{ route('home') }}#benefits">Benefits</a>
                        <a class="block rounded-md px-3 py-2 hover:bg-surface-1" href="{{ route('home') }}#how-it-works">How It Works</a>
                        <a class="block rounded-md px-3 py-2 hover:bg-surface-1" href="{{ route('home') }}#pricing">Pricing</a>
                        <a class="block rounded-md px-3 py-2 hover:bg-surface-1" href="{{ route('about') }}">About Us</a>
                        <a class="btn btn-secondary mt-2 w-full" href="{{ route('login') }}">Business Portal</a>
                    </nav>
                </div>
            @endif
        </div>
    </header>

    <main id="main">
        @yield('content')
    </main>

    {{-- Minimal chrome (vertical landings) drops the footer too:
         every link on those pages is an exit the visit was paid
         to create (F-22). The consent banner always stays. --}}
    @unless (trim($__env->yieldContent('chrome')) === 'minimal')
        @include('partials.marketing-footer')
    @endunless
    @include('partials.consent-banner')
</body>
</html>
