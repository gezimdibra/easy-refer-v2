@extends('layouts.marketing')

@section('chrome', 'minimal')

@section('title', $vertical['h1'].' | Easy Refer')

@section('meta_description', 'Easy Refer for '.\Illuminate\Support\Str::plural($vertical['type']).': your regulars bring your next clients. Free to start, and you pay only when a brand-new customer walks in and redeems.')

@section('content')
    {{-- Vertical landing (spec Section 2.6): minimal chrome, the
         offer above the fold, the CTA reachable without scrolling
         at 360px (AP-04), and nothing beyond the specced sections.
         Zero statistic or testimonial elements (AP-07). The hero
         visual is per vertical (landing-page-spec.md Section
         4.11): a real store photo for cafes and boutiques, the
         printed poster artwork for barbers and salons. --}}
    <section class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <div class="max-w-[720px]">
            <h1 class="text-[32px] leading-[38px] font-bold text-text-1 md:text-[40px] md:leading-[44px]">{{ $vertical['h1'] }}</h1>
            <p class="mt-3 text-lg text-text-2">{{ $vertical['line'] }}</p>
            <p class="mt-4 text-lg font-semibold text-text-1">Free to start. You pay {{ \App\Support\Money::usd($plan->per_referral_cents) }} only when a brand-new customer walks in and redeems. Never more than {{ \App\Support\Money::usd($plan->monthly_cap_cents) }} a month.</p>
            <p class="mt-2 text-base text-text-2">Your first {{ (int) config('billing.free_visits_at_signup') }} verified visits are free.</p>
            <div class="mt-6 flex flex-wrap items-center gap-4">
                <a class="btn btn-primary" href="{{ route('register', ['type' => $vertical['type']]) }}">Start free</a>
                <a class="btn btn-ghost" href="#how-it-works">See how it works</a>
            </div>
        </div>
        @php($visual = $vertical['visual'])
        @if ($visual['kind'] === 'photo')
            <picture>
                <source type="image/webp" srcset="{{ asset($visual['base'].'-480.webp') }} 480w, {{ asset($visual['base'].'-768.webp') }} 768w, {{ asset($visual['base'].'-1080.webp') }} 1080w" sizes="(min-width: 1024px) 640px, 100vw">
                <source type="image/jpeg" srcset="{{ asset($visual['base'].'-480.jpg') }} 480w, {{ asset($visual['base'].'-768.jpg') }} 768w, {{ asset($visual['base'].'-1080.jpg') }} 1080w" sizes="(min-width: 1024px) 640px, 100vw">
                <img src="{{ asset($visual['base'].'-768.jpg') }}" width="{{ $visual['width'] }}" height="{{ $visual['height'] }}" alt="{{ $visual['alt'] }}" loading="lazy" decoding="async" class="mx-auto mt-10 w-full max-w-[640px] rounded-lg border border-line shadow-e2">
            </picture>
        @else
            <picture>
                <source type="image/webp" srcset="{{ asset($visual['base'].'-480.webp') }} 480w" sizes="(min-width: 1024px) 420px, 100vw">
                <img src="{{ asset($visual['base'].'-480.jpg') }}" width="{{ $visual['width'] }}" height="{{ $visual['height'] }}" alt="{{ $visual['alt'] }}" loading="lazy" decoding="async" class="mx-auto mt-10 w-full max-w-[420px] rounded-lg border border-line shadow-e2">
            </picture>
        @endif
    </section>

    <section id="how-it-works" class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <h2 class="text-2xl font-bold text-text-1">How It Works</h2>
        <div class="mt-8">
            @include('partials.how-it-works')
        </div>
    </section>

    <section class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <h2 class="text-center text-2xl font-bold text-text-1">Simple, Transparent Pricing</h2>
        <div class="mt-8">
            @include('partials.pricing-plan')
        </div>
        <p class="mt-4 text-center text-sm text-text-2">Payments are handled by Square. Text messages are handled by Twilio.</p>
        @include('partials.calculator', ['registerUrl' => route('register', ['type' => $vertical['type']])])
    </section>

    <section class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <div class="max-w-[640px]">
            <h2 class="text-2xl font-bold text-text-1">{{ $vertical['objection'] }}</h2>
            <p class="mt-3 text-lg text-text-2">{{ $vertical['objection_answer'] }}</p>
        </div>
    </section>

    <section class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <h2 class="text-2xl font-bold text-text-1">FAQ</h2>
        <div class="mt-6 max-w-[640px]">
            @include('partials.faq-list', ['faqs' => $faqs, 'heading' => 'h3'])
        </div>
    </section>

    {{-- Closing band (inventory LAND-C2): the offer restated once,
         the accent button via the component (CF-07). --}}
    <section class="on-dark bg-ink-900">
        <div class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
            <p class="text-xl font-semibold text-white">Free to start. You pay {{ \App\Support\Money::usd($plan->per_referral_cents) }} only when a brand-new customer walks in and redeems. Never more than {{ \App\Support\Money::usd($plan->monthly_cap_cents) }} a month.</p>
            <a class="btn btn-accent mt-6" href="{{ route('register', ['type' => $vertical['type']]) }}">Start free</a>
        </div>
    </section>

    @include('partials.starter-kit', ['source' => 'vertical_'.$vertical['type']])
@endsection
