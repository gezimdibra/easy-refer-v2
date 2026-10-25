<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#FAF6EF">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#161310">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Platform console')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-surface-1 text-text-1">
    <a class="skip-link" href="#main">Skip to content</a>

    {{-- The platform console shell (spec Section 15): its own
         layout, its own guard, no tenant navigation, nothing from
         the owner area. --}}
    <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-surface-0 px-5">
        <p class="text-lg font-semibold text-text-1">Platform console</p>
        @auth('admin')
            <nav aria-label="Console" class="ml-4 hidden gap-4 text-base md:flex">
                <a href="{{ route('admin.home') }}" @if (request()->routeIs('admin.home')) aria-current="page" class="font-semibold text-link underline" @else class="font-semibold text-link hover:underline" @endif>Overview</a>
                <a href="{{ route('admin.businesses') }}" @if (request()->routeIs('admin.businesses*')) aria-current="page" class="font-semibold text-link underline" @else class="font-semibold text-link hover:underline" @endif>Businesses</a>
                <a href="{{ route('admin.risk-feed') }}" @if (request()->routeIs('admin.risk-feed')) aria-current="page" class="font-semibold text-link underline" @else class="font-semibold text-link hover:underline" @endif>Risk feed</a>
                <a href="{{ route('admin.holds') }}" @if (request()->routeIs('admin.holds')) aria-current="page" class="font-semibold text-link underline" @else class="font-semibold text-link hover:underline" @endif>Review holds</a>
                <a href="{{ route('admin.audit') }}" @if (request()->routeIs('admin.audit')) aria-current="page" class="font-semibold text-link underline" @else class="font-semibold text-link hover:underline" @endif>Audit log</a>
            </nav>
            <form method="POST" action="{{ route('admin.logout') }}" class="ml-auto">
                @csrf
                <button type="submit" class="btn btn-ghost">Sign out</button>
            </form>
        @endauth
    </header>

    <main id="main" aria-label="@yield('title')" class="ui-scope mx-auto max-w-[1200px] px-5 py-6">
        @include('partials.notices', [
            'summaryOnly' => trim($__env->yieldContent('notices_summary_only')) === '1',
            'bannerKeys' => array_filter(array_map('trim', explode(',', trim($__env->yieldContent('notices_banner_keys'))))),
        ])
        @yield('content')
    </main>
</body>
</html>
