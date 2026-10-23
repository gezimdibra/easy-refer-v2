<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- The customer flow is never an acquisition audience: no
         analytics tags load here (spec Section 16). --}}
    <meta name="robots" content="noindex">
    <title>@yield('title', config('app.name'))</title>
    @vite(['resources/css/app.css', 'resources/js/flow.js'])
</head>
<body class="bg-surface-1 text-text-1">
    {{-- Customer flow skeleton (design-system.md Section 2.4): one
         480px column at every width, the wordmark and the step
         indicator on a slim top row, one card, no navigation. The
         screen's one task is the whole hierarchy. --}}
    <div class="mx-auto w-full max-w-[480px] px-3 py-4 text-lg sm:px-0">
        <div class="flex items-center justify-between px-1">
            <span class="text-lg font-bold">Easy Refer</span>
            @yield('step')
        </div>

        <main aria-label="@yield('flow_label', 'Referral')" class="@yield('main_class', 'card mt-3')">
            @yield('content')
        </main>

        @hasSection('action')
            {{-- Sticky bottom action bar (design Sections 2.4, 6.5):
                 the screen's one primary action lives in thumb reach
                 and never covers content (scroll padding below). --}}
            <div class="sticky bottom-0 z-20 mt-3 bg-surface-1 pb-2 pt-2">
                @yield('action')
            </div>
        @endif
    </div>
</body>
</html>
