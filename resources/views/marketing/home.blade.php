@extends('layouts.marketing')

@section('title', 'Easy Refer: Turn Your Customers Into Your Best Marketers')

@section('content')
    {{-- Hero (design-system.md Section 3.1): the promise, one
         supporting sentence, and the two actions, inside the first
         viewport (AP-04). The visual is the real owner dashboard
         (Section 6.3 asset rule, AP-07), photographed from a seeded
         demo business in Sprint 3. --}}
    <section class="mx-auto max-w-[1120px] px-5 py-12 md:py-16">
        <div class="max-w-[720px]">
            <h1 class="text-4xl font-bold leading-tight text-text-1 md:text-[40px] md:leading-[44px]">Turn Your Customers Into Your Best Marketers</h1>
            <p class="mt-4 text-lg text-text-2">Easy Refer turns your customers into referrers with QR codes, verified visits, and rewards you only pay for when they work.</p>
            <div class="mt-6 flex flex-wrap items-center gap-4">
                <a class="btn btn-primary" href="{{ route('register') }}">Start free</a>
                <a class="font-semibold text-brand-700 hover:underline" href="#how-it-works">See how it works</a>
            </div>
        </div>
        <img src="{{ asset('images/dashboard-overview.png') }}" alt="The Easy Refer owner dashboard, showing today's scans, completed referrals, rewards waiting to be claimed, and a chart of referred customers" class="mt-10 w-full rounded-lg border border-line shadow-e2" loading="lazy">
    </section>

    <section id="how-it-works" class="mx-auto max-w-[1120px] px-5 py-12">
        <h2 class="text-2xl font-bold text-text-1">How It Works</h2>
        <div class="mt-8">
            @include('partials.how-it-works')
        </div>
    </section>

    {{-- Benefits render as text blocks without card chrome (AP-03),
         and with zero statistic elements while OD-6 is open (AP-07). --}}
    <section id="benefits" class="mx-auto max-w-[1120px] px-5 py-12">
        <h2 class="text-2xl font-bold text-text-1">Why Use Easy Refer?</h2>
        <div class="mt-8 grid gap-8 md:grid-cols-3">
            <div>
                <h3 class="text-xl font-semibold text-text-1">Attract New Customers</h3>
                <p class="mt-2 text-base text-text-2">Encourage your existing customers to refer their friends. Our platform makes it easy for satisfied customers to spread the word about your business.</p>
            </div>
            <div>
                <h3 class="text-xl font-semibold text-text-1">Increase Revenue</h3>
                <p class="mt-2 text-base text-text-2">Drive repeat business with smart incentives. Our referral and messaging system encourages both referrers and new customers to keep coming back.</p>
            </div>
            <div>
                <h3 class="text-xl font-semibold text-text-1">Affordable Pricing</h3>
                <p class="mt-2 text-base text-text-2">Only pay for success. Our transparent pricing means you only pay when new customers actually visit your business. Result-based pricing.</p>
            </div>
        </div>
    </section>

    <section id="features" class="mx-auto max-w-[1120px] px-5 py-12">
        <h2 class="text-2xl font-bold text-text-1">Powerful Features</h2>
        <div class="mt-8 grid gap-8 md:grid-cols-2">
            <div class="card">
                <h3 class="text-xl font-semibold text-text-1">QR Code Referrals</h3>
                <p class="mt-2 text-base text-text-2">Generate Referral Codes for your business in seconds.</p>
            </div>
            <div class="card">
                <h3 class="text-xl font-semibold text-text-1">SMS Marketing</h3>
                <p class="mt-2 text-base text-text-2">Send SMS campaigns to your customers who agreed to get them.</p>
            </div>
        </div>
    </section>

    {{-- Success Stories render nothing while OD-6 is open: an empty
         proof section costs more trust than its absence (AP-07). --}}

    <section id="pricing" class="mx-auto max-w-[1120px] px-5 py-12">
        <h2 class="text-center text-2xl font-bold text-text-1">Simple, Transparent Pricing</h2>
        <p class="mt-2 text-center text-lg text-text-2">One plan: free to start, {{ \App\Support\Money::usd($plan->per_referral_cents) }} per verified referral visit, never more than {{ \App\Support\Money::usd($plan->monthly_cap_cents) }} a month.</p>
        <div class="mt-8">
            @include('partials.pricing-plan')
        </div>
        @include('partials.calculator')
    </section>

    {{-- Closing CTA (HOME-CTA-01 heading approved; its body line is
         PENDING in the inventory, so no body line renders). --}}
    <section class="bg-ink-900">
        <div class="mx-auto max-w-[1120px] px-5 py-12">
            <h2 class="text-2xl font-bold text-white">Ready to Grow Your Business?</h2>
            <ul class="mt-4 space-y-1 text-base text-white">
                <li>Set up in minutes</li>
                <li>Nothing charged until after your first {{ (int) config('billing.free_visits_at_signup') }} free visits</li>
                <li>24/7 Support</li>
            </ul>
            <a class="btn mt-6 bg-accent-400 font-semibold text-ink-900 hover:bg-accent-500" href="{{ route('register') }}">Start free</a>
        </div>
    </section>

    @include('partials.starter-kit', ['source' => 'home'])
@endsection
