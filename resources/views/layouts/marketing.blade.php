<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"{!! trim($__env->yieldContent('theme_lock')) === 'light' ? ' class="landing-light-lock"' : '' !!}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="{{ trim($__env->yieldContent('theme_lock')) === 'light' ? 'only light' : 'light dark' }}">
    {{-- SEO and social metadata (landing-page-spec.md Section 7):
         one implementation here, per-page description overrides via
         the meta_description section. Every URL is built from the
         app URL config, so the set is correct at both mounts. --}}
    @php
        $metaTitle = trim($__env->yieldContent('title', config('app.name')));
        $metaDescription = trim($__env->yieldContent('meta_description', 'Easy Refer turns your customers into referrers with QR codes, visits that count, and rewards you only pay for when they work.'));
        $metaUrl = url()->current();
        $metaImage = asset('images/photos/og-image.png');
    @endphp
    <meta name="description" content="{{ $metaDescription }}">
    <title>{{ $metaTitle }}</title>
    <link rel="canonical" href="{{ $metaUrl }}">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#FAF6EF">
    {{-- A light-locked page (theme_lock section) never presents
         the dark chrome tint: it renders light at any setting. --}}
    @if (trim($__env->yieldContent('theme_lock')) !== 'light')
        <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#161310">
    @endif
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Easy Refer">
    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:url" content="{{ $metaUrl }}">
    <meta property="og:image" content="{{ $metaImage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Easy Refer: Get Your Customers to Refer Their Friends. Referral marketing for local businesses.">
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
    {{-- SL-11: the marketing header hides on scroll down and returns
         on scroll up (the script sits at the end of the body). The
         transition stays inside the MO-02 duration cap and is
         removed under reduced motion, where the header simply
         stays in place. A transform never shifts layout (PF-04).
         The rules live in app.css, with the M-04 header tap
         targets (at least 48px for the logo link and the menu
         toggle), graduated at the release 07 integration. --}}
</head>
<body class="bg-surface-0 text-text-1">
    <a class="skip-link" href="#main">Skip to content</a>

    <header id="site-header" class="sticky top-0 z-20 border-b border-line bg-surface-0">
        <div class="shell flex h-16 items-center justify-between gap-4">
            <a href="{{ route('home') }}" class="logo-link" aria-label="Easy Refer home"><x-logo variant="auto" :size="28" /></a>

            @if (trim($__env->yieldContent('chrome')) === 'minimal')
                {{-- Vertical landings (spec Section 2.6): the lockup
                     and one Sign in link only; every other link is an
                     exit the advertiser paid to create. --}}
                <a href="{{ route('login') }}" class="inline-flex min-h-12 items-center text-[15px] font-medium text-text-2 hover:text-text-1">Sign in</a>
            @else
                <nav aria-label="Site" class="hidden items-center gap-1 md:flex">
                    <a class="inline-flex min-h-12 items-center rounded-md px-3 text-[15px] font-medium text-text-2 hover:bg-surface-1 hover:text-text-1" href="{{ route('home') }}#how-it-works">How it works</a>
                    <a class="inline-flex min-h-12 items-center rounded-md px-3 text-[15px] font-medium text-text-2 hover:bg-surface-1 hover:text-text-1" href="{{ route('home') }}#benefits">Benefits</a>
                    <a class="inline-flex min-h-12 items-center rounded-md px-3 text-[15px] font-medium text-text-2 hover:bg-surface-1 hover:text-text-1" href="{{ route('home') }}#features">Features</a>
                    <a class="inline-flex min-h-12 items-center rounded-md px-3 text-[15px] font-medium text-text-2 hover:bg-surface-1 hover:text-text-1" href="{{ route('home') }}#pricing">Pricing</a>
                    <a class="inline-flex min-h-12 items-center rounded-md px-3 text-[15px] font-medium text-text-2 hover:bg-surface-1 hover:text-text-1" href="{{ route('about') }}">About us</a>
                    <span class="ml-3 flex items-center gap-2">
                        <a href="{{ route('login') }}" class="btn btn-ghost btn-sm">Sign in</a>
                        <a href="{{ route('register') }}" class="btn btn-primary btn-sm">Start free</a>
                    </span>
                </nav>

                <div class="relative md:hidden" x-data="{ open: false }">
                    <button type="button" class="menu-toggle inline-flex h-12 w-12 items-center justify-center rounded-md text-text-1 hover:bg-surface-1" aria-label="Open menu" aria-haspopup="true" :aria-expanded="open.toString()" @click="open = ! open"><x-icon name="menu" :size="24" /></button>
                    <nav aria-label="Site menu" x-show="open" x-cloak class="absolute right-0 z-40 mt-2 w-60 rounded-lg border border-line bg-surface-0 p-2 shadow-e2">
                        <a class="block rounded-md px-3 py-3 text-base hover:bg-surface-1" href="{{ route('home') }}#how-it-works">How it works</a>
                        <a class="block rounded-md px-3 py-3 text-base hover:bg-surface-1" href="{{ route('home') }}#benefits">Benefits</a>
                        <a class="block rounded-md px-3 py-3 text-base hover:bg-surface-1" href="{{ route('home') }}#features">Features</a>
                        <a class="block rounded-md px-3 py-3 text-base hover:bg-surface-1" href="{{ route('home') }}#pricing">Pricing</a>
                        <a class="block rounded-md px-3 py-3 text-base hover:bg-surface-1" href="{{ route('about') }}">About us</a>
                        {{-- C-04: the open mobile menu carries the primary
                             action, so a convinced visitor is never
                             stranded at the moment of intent. --}}
                        <a class="btn btn-primary mt-2 w-full" href="{{ route('register') }}">Start free</a>
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

    {{-- SL-11 behavior: hide the marketing header while scrolling
         down, bring it back the moment the direction reverses.
         This layout is marketing-only; the app shell and the flow
         action bar live in other layouts and never hide. Reduced
         motion opts out entirely, and the header also stays put
         while the mobile menu is open or focus is inside it, so it
         can never carry a focused element off screen (SL-09). --}}
    <script>
        (function () {
            var header = document.getElementById('site-header');
            if (!header || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return;
            }
            var lastY = window.scrollY;
            var hidden = false;
            function onScroll() {
                var y = window.scrollY;
                var menuOpen = header.querySelector('[aria-expanded="true"]') !== null;
                var focusInside = header.contains(document.activeElement);
                if (!hidden && y > 96 && y > lastY + 4 && !menuOpen && !focusInside) {
                    header.classList.add('site-header-hidden');
                    hidden = true;
                } else if (hidden && (y < lastY - 4 || y <= 96)) {
                    header.classList.remove('site-header-hidden');
                    hidden = false;
                }
                lastY = y;
            }
            window.addEventListener('scroll', onScroll, { passive: true });
        })();
    </script>
</body>
</html>
