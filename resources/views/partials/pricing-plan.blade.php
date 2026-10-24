{{--
    The single plan card (inventory HOME-PRC-02, spec Section 2.1
    item 6): rendered from the plan table everywhere it appears,
    never hand-typed. Exactly one card exists (AP-03). Redesign
    (redesign-spec.md Section 10.1 item 8): radius-2xl, shadow-e2,
    padding 40px, bullets as check rows, and the CTA primary lg
    full width. The price line stays one contiguous string: the
    suite pins "$0/month, $2 per verified referral visit" verbatim,
    so it renders as a single title-2 line rather than the split
    display treatment.
--}}
@php($plan = $plan ?? \App\Models\Plan::launch())
<div class="mx-auto max-w-[620px] rounded-2xl border border-line bg-surface-0 shadow-e2" style="padding: 40px">
    <p class="type-eyebrow text-brand-700">{{ $plan->name }}</p>
    <p class="type-title-2 mt-4 text-text-1">{{ \App\Support\Money::usd($plan->price_cents ?? 0) }}/month, {{ \App\Support\Money::usd($plan->per_referral_cents) }} per verified referral visit</p>
    <ul class="mt-6 space-y-3">
        <li class="type-body flex items-start gap-2 text-text-2"><x-icon name="check" :size="20" class="mt-1 flex-none text-success-strong" />Free to start</li>
        <li class="type-body flex items-start gap-2 text-text-2"><x-icon name="check" :size="20" class="mt-1 flex-none text-success-strong" />First {{ (int) config('billing.free_visits_at_signup') }} verified visits free</li>
        <li class="type-body flex items-start gap-2 text-text-2"><x-icon name="check" :size="20" class="mt-1 flex-none text-success-strong" />Never more than {{ \App\Support\Money::usd($plan->monthly_cap_cents) }}/month</li>
        <li class="type-body flex items-start gap-2 text-text-2"><x-icon name="check" :size="20" class="mt-1 flex-none text-success-strong" />No setup fee</li>
        <li class="type-body flex items-start gap-2 text-text-2"><x-icon name="check" :size="20" class="mt-1 flex-none text-success-strong" />Cancel anytime</li>
    </ul>
    {{-- The decision point carries the Primary (design Section 3.1
         item 7; AP-08 as amended 2026-10-23 scopes one Primary per
         viewport region, and the pricing card never shares a
         viewport with the hero). --}}
    <a class="btn btn-primary btn-lg mt-8 w-full" href="{{ route('register') }}">Start free</a>
</div>
