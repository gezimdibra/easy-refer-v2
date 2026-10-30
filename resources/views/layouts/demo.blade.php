<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="bg-surface-1">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#FAF6EF">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#161310">
    {{-- The demo is never indexed and never an OG surface
         (Amendment 63, Section 13.3). --}}
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Try it live | Easy Refer')</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/flow.js'])
</head>
<body class="bg-surface-1 text-text-1">
    <a class="skip-link" href="#main">Skip to content</a>
    {{-- The demo surface: the flow skeleton with the DEMO
         device (Section 9) riding every screen. Inside the
         landing frame the surface reports its height to the
         parent so the frame fits the current screen and the
         page scroll is never trapped. --}}
    <div class="mx-auto w-full max-w-[480px] px-3 py-4 text-lg sm:px-0">
        <div class="flex items-center justify-between px-1">
            <x-logo variant="auto" :size="20" />
        </div>

        <div class="mt-3">
            @yield('banner')
        </div>

        <main id="main" aria-label="@yield('flow_label', 'Demo')" class="card mt-3 rounded-xl">
            @yield('content')
        </main>

        @hasSection('action')
            <div class="sticky bottom-0 z-20 mt-3 border-t border-line bg-surface-0 px-4 py-3 shadow-e2" style="padding-bottom: calc(12px + env(safe-area-inset-bottom))">
                @yield('action')
            </div>
        @endif
    </div>

    <script>
        (function () {
            function postHeight() {
                if (window.parent === window) { return; }
                var height = Math.ceil(document.documentElement.getBoundingClientRect().height);
                window.parent.postMessage({ type: 'er-demo-height', height: height }, window.location.origin);
            }
            window.addEventListener('load', postHeight);
            window.addEventListener('resize', postHeight);
            if (document.fonts && document.fonts.ready) { document.fonts.ready.then(postHeight); }
            // The hero's "Try it live" tap starts the demo from
            // the parent page (one tap, Section 2.2): the start
            // card's own form submits with the hero entry point.
            // Once-guard (F-C, landing integration pass): however
            // many start posts arrive in this frame load, the
            // start form submits at most once; the parent posts
            // on frame load, when this card exists, so a post is
            // never the mechanism's problem before the card does.
            var demoStartHandled = false;
            window.addEventListener('message', function (event) {
                if (event.origin !== window.location.origin) { return; }
                if (!event.data || event.data.type !== 'er-demo-start') { return; }
                if (demoStartHandled) { return; }
                var form = document.getElementById('demo-start-form');
                if (form) {
                    demoStartHandled = true;
                    var field = form.querySelector('input[name="entry_point"]');
                    if (field) { field.value = 'hero'; }
                    form.submit();
                }
            });
        })();
    </script>
</body>
</html>
