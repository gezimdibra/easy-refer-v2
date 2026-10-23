@extends('layouts.marketing')

@section('chrome', 'minimal')

@section('title', $vertical['h1'].' | Easy Refer')

@section('content')
    {{-- Vertical landing (spec Section 2.6): minimal chrome, the
         offer above the fold, the CTA reachable without scrolling
         at 360px (AP-04), and nothing beyond the specced sections.
         Zero statistic or testimonial elements (AP-07). --}}
    <section class="mx-auto max-w-[1120px] px-5 py-10">
        <div class="max-w-[720px]">
            <h1 class="text-4xl font-bold leading-tight text-text-1">{{ $vertical['h1'] }}</h1>
            <p class="mt-3 text-lg text-text-2">{{ $vertical['line'] }}</p>
            <p class="mt-4 text-lg font-medium text-text-1">Free to start. You pay {{ \App\Support\Money::usd($plan->per_referral_cents) }} only when a brand-new customer walks in and redeems. Never more than {{ \App\Support\Money::usd($plan->monthly_cap_cents) }} a month.</p>
            <p class="mt-2 text-base text-text-2">Your first {{ (int) config('billing.free_visits_at_signup') }} verified visits are free.</p>
            <div class="mt-6 flex flex-wrap items-center gap-4">
                <a class="btn btn-primary" href="{{ route('register', ['type' => $vertical['type']]) }}">Start free</a>
                <a class="font-semibold text-brand-700 hover:underline" href="#how-it-works">See how it works</a>
            </div>
        </div>
    </section>

    <section id="how-it-works" class="mx-auto max-w-[1120px] px-5 py-10">
        <h2 class="text-2xl font-bold text-text-1">How It Works</h2>
        <div class="mt-8">
            @include('partials.how-it-works')
        </div>
    </section>

    <section class="mx-auto max-w-[1120px] px-5 py-10">
        <h2 class="text-center text-2xl font-bold text-text-1">Simple, Transparent Pricing</h2>
        <div class="mt-8">
            @include('partials.pricing-plan')
        </div>
        @include('partials.calculator', ['registerUrl' => route('register', ['type' => $vertical['type']])])
    </section>

    <section class="mx-auto max-w-[1120px] px-5 py-10">
        <div class="max-w-[720px]">
            <h2 class="text-2xl font-bold text-text-1">{{ $vertical['objection'] }}</h2>
            <p class="mt-3 text-lg text-text-2">{{ $vertical['objection_answer'] }}</p>
        </div>
    </section>

    <section class="mx-auto max-w-[1120px] px-5 py-10">
        <h2 class="text-2xl font-bold text-text-1">FAQ</h2>
        <div class="mt-6 max-w-[720px] space-y-3">
            @foreach ($faqs as $i => $faq)
                <div x-data="{ open: false }" class="card">
                    <h3>
                        <button type="button" class="flex w-full items-center justify-between text-left text-lg font-semibold text-text-1" :aria-expanded="open.toString()" @click="open = ! open">
                            {{ $faq['q'] }}
                            <span aria-hidden="true" x-text="open ? '−' : '+'"></span>
                        </button>
                    </h3>
                    <div x-show="open" x-cloak>
                        <p class="mt-3 text-base text-text-2">{{ $faq['a'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="bg-ink-900">
        <div class="mx-auto max-w-[1120px] px-5 py-12">
            <p class="text-xl font-semibold text-white">Free to start. You pay {{ \App\Support\Money::usd($plan->per_referral_cents) }} only when a brand-new customer walks in and redeems. Never more than {{ \App\Support\Money::usd($plan->monthly_cap_cents) }} a month.</p>
            <a class="btn mt-6 bg-accent-400 font-semibold text-ink-900 hover:bg-accent-500" href="{{ route('register', ['type' => $vertical['type']]) }}">Start free</a>
        </div>
    </section>

    @include('partials.starter-kit', ['source' => 'vertical_'.$vertical['type']])
@endsection
