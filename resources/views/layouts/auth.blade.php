<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.analytics')
</head>
<body class="bg-surface-1 text-text-1">
    {{-- Auth skeleton (design-system.md Section 3.3, FND-6): one
         centered card at 440px, the wordmark above it, and the legal
         footer line under it. --}}
    <div class="mx-auto flex min-h-screen w-full max-w-[440px] flex-col px-5 py-10">
        <p class="text-center">
            <a href="{{ route('home') }}" class="text-2xl font-bold text-text-1">Easy Refer</a>
        </p>

        <main class="card ui-scope mt-6">
            {{-- The login and register views mark their fields
                 inline, so they opt into the summary-only error mode
                 (F-03); the other auth pages keep the message list
                 their forms rely on. --}}
            @include('partials.notices', [
                'summaryOnly' => trim($__env->yieldContent('notices_summary_only')) === '1',
                'bannerKeys' => array_filter(array_map('trim', explode(',', trim($__env->yieldContent('notices_banner_keys'))))),
            ])
            @yield('content')
        </main>

        <footer class="mt-6 text-center text-sm text-text-2">
            <nav aria-label="Legal" class="flex justify-center gap-4">
                <a class="text-brand-700 hover:underline" href="{{ route('legal') }}#privacy-policy">Privacy Policy</a>
                <a class="text-brand-700 hover:underline" href="{{ route('legal') }}#terms-of-service">Terms &amp; Conditions</a>
                <a class="text-brand-700 hover:underline" href="{{ route('about') }}#contact">Contact</a>
            </nav>
            <p class="mt-3">&copy; {{ now()->year }} Easy Refer</p>
        </footer>
    </div>
</body>
</html>
