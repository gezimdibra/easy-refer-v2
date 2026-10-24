{{--
    Dashboard rail content (redesign-spec.md Section 7.6): the
    lockup, the nav items, and the account footer. Shared by the
    desktop rail and the mobile slide-over so both render the same
    items. Items are 48px targets (the owner's 48px floor), with
    an 18px icon and a 15px label; the current page takes the
    brand-50 fill with brand-700 text at 600 and a brand-600 icon.
    The Review count badge keeps the .pill-danger component.
--}}
@php
    $railItems = [
        ['dashboard', 'dashboard', 'layout-dashboard', 'Overview'],
        ['customers.index', 'customers.*', 'users', 'Customers'],
        ['referrals.index', 'referrals.*', 'share-2', 'Referrals'],
        ['rewards.index', 'rewards.*', 'gift', 'Rewards'],
        ['reviews', 'reviews*', 'clipboard-check', 'Review'],
        ['owner.qr', 'owner.qr', 'qr-code', 'QR Code'],
        ['settings', 'settings*', 'settings', 'Settings'],
    ];
@endphp
<a href="{{ route('dashboard') }}" aria-label="Easy Refer home"><x-logo :size="32" /></a>

<ul class="mt-8 space-y-1">
    @foreach ($railItems as [$routeName, $pattern, $icon, $label])
        @php($active = $pattern === 'settings*' ? (request()->routeIs('settings*') || request()->routeIs('security*')) : request()->routeIs($pattern))
        <li>
            <a href="{{ route($routeName) }}" @if ($active) aria-current="page" @endif class="flex min-h-[48px] items-center gap-2.5 rounded-md px-3 text-[15px] {{ $active ? 'bg-brand-50 font-semibold text-brand-700' : 'font-medium text-text-2 hover:bg-surface-1 hover:text-text-1' }}">
                <span class="{{ $active ? 'text-brand-600' : '' }}"><x-icon name="{{ $icon }}" :size="20" /></span>
                {{ $label }}
                @if ($routeName === 'reviews' && ($reviewQueueCount ?? 0) > 0)
                    <span class="pill pill-danger ml-auto">{{ $reviewQueueCount }}</span>
                @endif
            </a>
        </li>
    @endforeach
</ul>

@auth
    <div class="mt-auto border-t border-line pt-4">
        <div class="flex items-center gap-3 px-3">
            <span class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-brand-50 text-brand-700" aria-hidden="true"><span style="font-size: 13px; font-weight: 650">{{ collect(explode(' ', trim(auth()->user()->name)))->map(fn ($w) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($w, 0, 1)))->take(2)->implode('') }}</span></span>
            <span class="min-w-0">
                <span class="block truncate text-sm font-semibold text-text-1">{{ auth()->user()->business?->name }}</span>
                <span class="type-caption block truncate text-text-3">{{ auth()->user()->email }}</span>
            </span>
        </div>
        <form method="POST" action="{{ route('logout') }}" class="mt-2">
            @csrf
            <button type="submit" class="flex min-h-[48px] w-full items-center gap-2.5 rounded-md px-3 text-[15px] font-medium text-text-2 hover:bg-surface-1 hover:text-text-1">
                <x-icon name="log-out" :size="20" /> Sign out
            </button>
        </form>
    </div>
@endauth
