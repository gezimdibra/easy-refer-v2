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
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/flow.js'])
</head>
<body class="bg-surface-1 text-text-1">
    <a class="skip-link" href="#main">Skip to content</a>
    {{-- Customer flow skeleton (design-system.md Section 2.4): one
         480px column at every width, the wordmark and the step
         indicator on a slim top row, one card, no navigation. The
         screen's one task is the whole hierarchy. --}}
    <div class="mx-auto w-full max-w-[480px] px-3 py-4 text-lg sm:px-0">
        <div class="flex items-center justify-between px-1">
            <x-logo :size="20" />
            @yield('step')
        </div>
        @php($stepNumber = (int) trim($__env->yieldContent('step_number')))
        @if ($stepNumber >= 1 && $stepNumber <= 3)
            <div class="mt-3 flex gap-1.5 px-1" aria-hidden="true">
                @foreach ([1, 2, 3] as $n)
                    <span class="segment {{ $n <= $stepNumber ? 'segment-on' : '' }}"></span>
                @endforeach
            </div>
        @endif

        <main id="main" aria-label="@yield('flow_label', 'Referral')" class="@yield('main_class', 'card mt-3 rounded-xl')">
            @yield('content')
        </main>

        @hasSection('action')
            {{-- Sticky bottom action bar (design Sections 2.4, 6.5):
                 the screen's one primary action lives in thumb reach
                 and never covers content (scroll padding below). --}}
            <div class="sticky bottom-0 z-20 mt-3 border-t border-line bg-surface-0 px-4 py-3 shadow-e2" style="padding-bottom: calc(12px + env(safe-area-inset-bottom))">
                @yield('action')
            </div>
        @endif
    </div>
</body>
</html>
