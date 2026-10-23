<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-surface-1 text-text-1">
    <a class="skip-link" href="#main">Skip to content</a>

    {{-- Dashboard skeleton (design-system.md Sections 2.4, 6.6): skip
         link, a labelled Primary nav (rail at lg, drawer below), a
         top bar, and main labelled by the page title. Only surfaces
         that exist are linked; unbuilt sections join this nav when
         their sprint ships them. --}}
    <div class="lg:flex">
        <nav aria-label="Primary" class="sticky top-0 hidden h-screen w-60 shrink-0 flex-col border-r border-line bg-surface-0 px-4 py-5 lg:flex">
            <a href="{{ route('dashboard') }}" class="text-xl font-bold text-text-1">Easy Refer</a>

            <ul class="mt-8 space-y-1 text-base">
                <li>
                    <a href="{{ route('dashboard') }}" @if (request()->routeIs('dashboard')) aria-current="page" @endif class="block rounded-md px-3 py-2 font-medium hover:bg-surface-1 {{ request()->routeIs('dashboard') ? 'bg-surface-1 text-brand-700' : 'text-text-1' }}">Overview</a>
                </li>
                <li>
                    <a href="{{ route('customers.index') }}" @if (request()->routeIs('customers.*')) aria-current="page" @endif class="block rounded-md px-3 py-2 font-medium hover:bg-surface-1 {{ request()->routeIs('customers.*') ? 'bg-surface-1 text-brand-700' : 'text-text-1' }}">Customers</a>
                </li>
                <li>
                    <a href="{{ route('referrals.index') }}" @if (request()->routeIs('referrals.*')) aria-current="page" @endif class="block rounded-md px-3 py-2 font-medium hover:bg-surface-1 {{ request()->routeIs('referrals.*') ? 'bg-surface-1 text-brand-700' : 'text-text-1' }}">Referrals</a>
                </li>
                <li>
                    <a href="{{ route('rewards.index') }}" @if (request()->routeIs('rewards.*')) aria-current="page" @endif class="block rounded-md px-3 py-2 font-medium hover:bg-surface-1 {{ request()->routeIs('rewards.*') ? 'bg-surface-1 text-brand-700' : 'text-text-1' }}">Rewards</a>
                </li>
                <li>
                    <a href="{{ route('reviews') }}" @if (request()->routeIs('reviews*')) aria-current="page" @endif class="block rounded-md px-3 py-2 font-medium hover:bg-surface-1 {{ request()->routeIs('reviews*') ? 'bg-surface-1 text-brand-700' : 'text-text-1' }}">Review @if (($reviewQueueCount ?? 0) > 0) <span class="pill pill-danger">{{ $reviewQueueCount }}</span> @endif</a>
                </li>
                <li>
                    <a href="{{ route('owner.qr') }}" @if (request()->routeIs('owner.qr')) aria-current="page" @endif class="block rounded-md px-3 py-2 font-medium hover:bg-surface-1 {{ request()->routeIs('owner.qr') ? 'bg-surface-1 text-brand-700' : 'text-text-1' }}">QR Code</a>
                </li>
                <li>
                    <a href="{{ route('settings') }}" @if (request()->routeIs('settings*') || request()->routeIs('security*')) aria-current="page" @endif class="block rounded-md px-3 py-2 font-medium hover:bg-surface-1 {{ request()->routeIs('settings*') || request()->routeIs('security*') ? 'bg-surface-1 text-brand-700' : 'text-text-1' }}">Settings</a>
                </li>
            </ul>

            @auth
                <div class="mt-auto border-t border-line pt-4">
                    <p class="px-3 text-sm text-text-2">{{ auth()->user()->business?->name }}</p>
                    <p class="px-3 text-sm font-medium text-text-1">{{ auth()->user()->name }}</p>
                    <p class="px-3 text-sm text-text-2">{{ auth()->user()->email }}</p>
                    <form method="POST" action="{{ route('logout') }}" class="mt-2">
                        @csrf
                        <button type="submit" class="btn btn-ghost w-full justify-start">Sign out</button>
                    </form>
                </div>
            @endauth
        </nav>

        <div class="min-w-0 flex-1">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-surface-0 px-5">
                <div class="relative lg:hidden" x-data="{ open: false }">
                    <button type="button" class="btn btn-secondary" aria-haspopup="true" :aria-expanded="open.toString()" @click="open = ! open">Menu</button>
                    <nav aria-label="Primary" x-show="open" x-cloak class="absolute left-0 z-40 mt-2 w-56 rounded-lg border border-line bg-surface-0 p-3 shadow-e2">
                        <a class="block rounded-md px-3 py-2 hover:bg-surface-1" @if (request()->routeIs('dashboard')) aria-current="page" @endif href="{{ route('dashboard') }}">Overview</a>
                        <a class="block rounded-md px-3 py-2 hover:bg-surface-1" @if (request()->routeIs('customers.*')) aria-current="page" @endif href="{{ route('customers.index') }}">Customers</a>
                        <a class="block rounded-md px-3 py-2 hover:bg-surface-1" @if (request()->routeIs('referrals.*')) aria-current="page" @endif href="{{ route('referrals.index') }}">Referrals</a>
                        <a class="block rounded-md px-3 py-2 hover:bg-surface-1" @if (request()->routeIs('rewards.*')) aria-current="page" @endif href="{{ route('rewards.index') }}">Rewards</a>
                        <a class="block rounded-md px-3 py-2 hover:bg-surface-1" @if (request()->routeIs('reviews*')) aria-current="page" @endif href="{{ route('reviews') }}">Review @if (($reviewQueueCount ?? 0) > 0) <span class="pill pill-danger">{{ $reviewQueueCount }}</span> @endif</a>
                        <a class="block rounded-md px-3 py-2 hover:bg-surface-1" @if (request()->routeIs('owner.qr')) aria-current="page" @endif href="{{ route('owner.qr') }}">QR Code</a>
                        <a class="block rounded-md px-3 py-2 hover:bg-surface-1" @if (request()->routeIs('settings*') || request()->routeIs('security*')) aria-current="page" @endif href="{{ route('settings') }}">Settings</a>
                        <form method="POST" action="{{ route('logout') }}" class="mt-2 border-t border-line pt-2">
                            @csrf
                            <button type="submit" class="btn btn-ghost w-full justify-start">Sign out</button>
                        </form>
                    </nav>
                </div>

                <p class="text-lg font-semibold text-text-1">@yield('title')</p>
                @auth
                    <p class="ml-auto hidden text-sm text-text-2 sm:block">{{ auth()->user()->business?->name }}</p>
                @endauth
            </header>

            <main id="main" aria-label="@yield('title')" class="ui-scope mx-auto max-w-[1200px] px-5 py-6">
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
