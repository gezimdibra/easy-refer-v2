{{-- ID-11 [COUP-01] (Amendment 53): the QR page's two tabs.
     "Referral codes" is the default and carries the core loop;
     "Coupons" is the secondary tab. The tab switches the whole
     code area: each tab is its own page render, never two
     panels stacked on one page (design pass Section 1.1).
     Tab styling follows the Settings nav pattern (pill links,
     aria-current on the active tab): a tab is navigation, not
     an action, so it never carries btn-primary; the QR page's
     one primary stays the poster print action (QF-1, MIX). --}}
<nav class="mt-6 flex gap-2" aria-label="Code types" data-coupon-tabs>
    <a href="{{ route('owner.qr') }}" @if (($active ?? 'referral') === 'referral') aria-current="page" class="flex min-h-[48px] items-center rounded-md border border-line-strong bg-brand-50 px-3 text-[15px] font-semibold whitespace-nowrap text-link" @else class="flex min-h-[48px] items-center rounded-md px-3 text-[15px] font-medium whitespace-nowrap text-text-2 hover:bg-surface-1 hover:text-text-1" @endif>Referral codes</a>
    <a href="{{ route('owner.qr.coupons') }}" @if (($active ?? 'referral') === 'coupons') aria-current="page" class="flex min-h-[48px] items-center rounded-md border border-line-strong bg-brand-50 px-3 text-[15px] font-semibold whitespace-nowrap text-link" @else class="flex min-h-[48px] items-center rounded-md px-3 text-[15px] font-medium whitespace-nowrap text-text-2 hover:bg-surface-1 hover:text-text-1" @endif>Coupons</a>
</nav>
