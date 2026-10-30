<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="bg-surface-0">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    {{-- SEAM-01: theme-color = the top band's token (surface-0 here), as the auth shell. --}}
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#FFFFFF">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#221D17">
    <title>@yield('title', config('app.name'))</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.analytics')
</head>
<body class="bg-surface-0 text-text-1">
    <a class="skip-link" href="#main">Skip to content</a>

    {{-- Onboarding shell (P4, design pack Section 4.0): the
         signup wizard's focused grammar, one centered column,
         no app rail. The exits live in the same places on every
         screen: the logo home, and "Skip setup for now" in the
         header, which leaves the flow for the dashboard (the
         resume surfaces there take over). Portal tokens only;
         the flow inherits the portal themes like every other
         portal surface. --}}
    <div class="mx-auto flex min-h-screen w-full max-w-[640px] flex-col px-5 py-8 sm:px-8">
        <header class="flex items-center justify-between gap-4">
            <a href="{{ route('home') }}" aria-label="Easy Refer home"><x-logo variant="auto" :size="32" /></a>
            @unless (trim($__env->yieldContent('hide_skip_setup')) === '1')
                <a class="text-action type-body-sm" href="{{ route('dashboard') }}">Skip setup for now</a>
            @endunless
        </header>

        <main id="main" class="ui-scope mx-auto flex w-full max-w-[560px] flex-1 flex-col py-10">
            @include('partials.notices')
            @yield('content')
        </main>

        <footer class="text-center">
            <p class="type-caption text-text-2">&copy; {{ now()->year }} Easy Refer</p>
        </footer>
    </div>
</body>
</html>
