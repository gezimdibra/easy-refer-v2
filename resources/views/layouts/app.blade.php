<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-surface-1 text-text-1">
    <a class="skip-link" href="#main">Skip to content</a>

    {{-- Dashboard skeleton (design-system.md Sections 2.4, 6.6;
         redesign-spec.md Section 7.6): skip link, a labelled
         Primary nav (rail at lg, the same rail as a slide-over
         below lg), a 60px top bar, and main labelled by the page
         title. Only surfaces that exist are linked; unbuilt
         sections join this nav when their sprint ships them. --}}
    <div class="lg:flex" x-data="{ navOpen: false }">
        <nav aria-label="Primary" class="sticky top-0 hidden h-screen w-60 shrink-0 flex-col border-r border-line bg-surface-0 px-4 py-6 lg:flex">
            @include('partials.app-rail')
        </nav>

        {{-- Mobile slide-over: the same rail, shadow-e3, over a
             scrim. --}}
        <div x-show="navOpen" x-cloak class="fixed inset-0 z-40 lg:hidden">
            <div class="absolute inset-0 bg-ink-900/60" @click="navOpen = false"></div>
            <nav aria-label="Primary" class="absolute inset-y-0 left-0 flex w-[280px] flex-col overflow-y-auto bg-surface-0 px-4 py-6 shadow-e3">
                <button type="button" class="absolute top-4 right-4 inline-flex h-11 w-11 items-center justify-center rounded-md text-text-2 hover:bg-surface-1 hover:text-text-1" aria-label="Close menu" @click="navOpen = false"><x-icon name="x" :size="20" /></button>
                @include('partials.app-rail')
            </nav>
        </div>

        <div class="min-w-0 flex-1">
            <header class="sticky top-0 z-20 flex h-[60px] items-center gap-3 border-b border-line bg-surface-0 px-5 md:px-8">
                <button type="button" class="inline-flex h-11 w-11 items-center justify-center rounded-md text-text-1 hover:bg-surface-1 lg:hidden" aria-label="Open menu" aria-haspopup="true" :aria-expanded="navOpen.toString()" @click="navOpen = true"><x-icon name="menu" :size="24" /></button>

                {{-- The top-bar title yields to the page's own
                     H1 below lg, so a phone never states the
                     page title twice (guardian observation,
                     corrections wave); at lg the bar keeps it. --}}
                <p class="type-title-3 hidden text-text-1 lg:block">@yield('title')</p>
                @auth
                    <span class="ml-auto flex items-center gap-3">
                        <span class="hidden rounded-full bg-surface-1 px-3 py-1.5 text-[13px] font-medium text-text-1 sm:block">{{ auth()->user()->business?->name }}</span>
                        <span class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-brand-50 text-brand-700" aria-hidden="true"><span style="font-size: 13px; font-weight: 650">{{ collect(explode(' ', trim(auth()->user()->name)))->map(fn ($w) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($w, 0, 1)))->take(2)->implode('') }}</span></span>
                    </span>
                @endauth
            </header>

            <main id="main" aria-label="@yield('title')" class="ui-scope mx-auto max-w-[1200px] px-5 py-8 md:px-8">
                {{-- Same notices contract as the auth layout: a
                     page whose forms mark their fields declares
                     notices_summary_only so each problem is stated
                     exactly once, at its field (design 4.4 rule
                     6); cross-field errors ride the banner keys
                     the page names. Pages that declare neither
                     keep the list mode their forms were built
                     against. --}}
                @include('partials.notices', [
                    'summaryOnly' => trim($__env->yieldContent('notices_summary_only')) === '1',
                    'bannerKeys' => array_filter(array_map('trim', explode(',', trim($__env->yieldContent('notices_banner_keys'))))),
                ])
                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
