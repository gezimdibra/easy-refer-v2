<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="portal-theme bg-surface-0">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    {{-- SEAM-01: theme-color = the top band's token (surface-0 here), pinned by ThemeColorSeamTest against app.css. --}}
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#FFFFFF">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#161B22">
    <title>@yield('title', config('app.name'))</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.analytics')
</head>
<body class="bg-surface-0 text-text-1">
    <a class="skip-link" href="#main">Skip to content</a>

    {{-- Auth shell (redesign-spec.md Section 10.4): split screen
         from lg. Left, the form column on surface-0 with no card
         chrome; right, an ink-950 brand panel carrying the product
         window, the approved stat row, and the Ralston quote. The
         panel duplicates marketing claims, so it is aria-hidden:
         nothing in it is needed to complete the form. Below lg the
         panel disappears and the lockup centers over the form. --}}
    <div class="flex min-h-screen">
        <div class="flex w-full flex-col px-5 py-8 sm:px-8 lg:w-[520px] lg:flex-none lg:px-10">
            <p class="flex justify-center lg:justify-start">
                <a href="{{ route('home') }}" aria-label="Easy Refer home"><x-logo variant="auto" :size="32" /></a>
            </p>

            <main id="main" class="ui-scope auth-scope mx-auto flex w-full max-w-[440px] flex-1 flex-col justify-center py-10">
                {{-- One notices contract app-wide (EMPTY ERROR
                     SUMMARY class rule, 2026-10-29): the summary
                     counts AND lists every problem, on every page. --}}
                @include('partials.notices')
                @yield('content')
            </main>

            <footer class="text-center lg:text-left">
                <nav aria-label="Legal" class="flex flex-wrap justify-center gap-x-5 gap-y-2 lg:justify-start">
                    {{-- TX-D4 (release 08b): the legal links stand
                         at least 24px tall via padding (they
                         measured 18px, under the WCAG 2.5.8
                         floor); the wording and size class are
                         unchanged. TX-D5: the copyright caption
                         moves from text-3 (6.79:1) to text-2 so
                         no caption on the auth surfaces sits
                         under the 7:1 daylight floor. --}}
                    <a class="type-caption inline-flex min-h-6 items-center text-text-2 hover:text-text-1" href="{{ route('legal') }}#privacy-policy">Privacy Policy</a>
                    <a class="type-caption inline-flex min-h-6 items-center text-text-2 hover:text-text-1" href="{{ route('legal') }}#terms-of-service">Terms &amp; Conditions</a>
                    <a class="type-caption inline-flex min-h-6 items-center text-text-2 hover:text-text-1" href="{{ route('about') }}#contact">Contact</a>
                </nav>
                <p class="type-caption mt-3 text-text-2">&copy; {{ now()->year }} Easy Refer</p>
            </footer>
        </div>

        <aside aria-hidden="true" class="on-dark hidden flex-1 bg-ink-950 lg:block" style="padding: 64px">
            <div class="flex h-full flex-col justify-center">
                <div class="window border-on-dark-line">
                    <div class="window-bar" aria-hidden="true">
                        <span class="window-dot"></span><span class="window-dot"></span><span class="window-dot"></span>
                        <span class="window-url">easy-refer.com/auth</span>
                        <span class="w-10"></span>
                    </div>
                    <img src="{{ asset('images/dashboard-overview-1080.jpg') }}" width="1080" height="810" alt="" class="block w-full">
                </div>

                <div class="mt-12 grid grid-cols-3 gap-6 border-y border-on-dark-line py-8">
                    <div>
                        <p class="type-stat text-white">{{ config('claims.new_customers_per_year') }}</p>
                        <p class="type-caption mt-2 text-dark-muted">new customers per year on average</p>
                    </div>
                    <div>
                        <p class="type-stat text-white">{{ config('claims.higher_purchase') }}</p>
                        <p class="type-caption mt-2 text-dark-muted">higher purchase amount</p>
                    </div>
                    <div>
                        <p class="type-stat text-white">{{ config('claims.retention_rate') }}</p>
                        <p class="type-caption mt-2 text-dark-muted">customer retention</p>
                    </div>
                </div>

                <blockquote class="mt-12">
                    <p class="text-white" style="font-size: 17px; line-height: 27px">We used to spend a lot of money on Google Ads. Now our customers do the marketing for us.</p>
                    <p class="type-caption mt-3 text-dark-muted">Nolan and Megan Ralston, Owners, Pizzeria</p>
                </blockquote>
            </div>
        </aside>
    </div>
</body>
</html>
