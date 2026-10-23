{{--
    The single plan card (inventory HOME-PRC-02, spec Section 2.1
    item 6): rendered from the plan table everywhere it appears,
    never hand-typed. Exactly one card exists (AP-03).
--}}
@php($plan = $plan ?? \App\Models\Plan::launch())
<div class="card mx-auto max-w-[560px]">
    <h3 class="text-xl font-semibold text-text-1">{{ $plan->name }}</h3>
    <p class="mt-3 text-2xl font-bold text-text-1">
        {{ \App\Support\Money::usd($plan->price_cents ?? 0) }}/month, {{ \App\Support\Money::usd($plan->per_referral_cents) }} per verified referral visit
    </p>
    <ul class="mt-5 space-y-2 text-base text-text-2">
        <li>Free to start</li>
        <li>First {{ (int) config('billing.free_visits_at_signup') }} verified visits free</li>
        <li>Never more than {{ \App\Support\Money::usd($plan->monthly_cap_cents) }}/month</li>
        <li>No setup fee</li>
        <li>Cancel anytime</li>
    </ul>
    {{-- The decision point carries the Primary (design Section 3.1
         item 7; AP-08 as amended 2026-10-23 scopes one Primary per
         viewport region, and the pricing card never shares a
         viewport with the hero). --}}
    <a class="btn btn-primary mt-6 w-full" href="{{ route('register') }}">Start free</a>
</div>
