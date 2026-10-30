@extends('layouts.marketing')

@section('chrome', 'minimal')

@section('title', $vertical['h1'].' | Easy Refer')

@section('meta_description', 'Easy Refer for '.\Illuminate\Support\Str::plural($vertical['type']).': '.lcfirst($vertical['h1']).'. Free to start, and you pay only when a brand-new customer walks in and redeems.')

@section('content')
    {{-- Vertical landing (spec Section 2.6): minimal chrome, the
         offer above the fold, the CTA reachable without scrolling
         at 360px (AP-04), and nothing beyond the specced sections.
         Zero statistic or testimonial elements (AP-07). The hero
         visual is per vertical (landing-page-spec.md Section
         4.11): a real store photo for cafes and boutiques, the
         printed poster artwork for barbers and salons. --}}
    <section class="shell section-pad">
        <div class="max-w-[720px]">
            <h1 class="type-title-1 text-text-1">{{ $vertical['h1'] }}</h1>
            {{-- P-06 (release 08b): two mechanisms together. The
                 stack's margins tighten (about 20px across it), the
                 two lede blocks take the compact mobile step (the
                 .v-line/.v-offer rule in app.css), and the
                 first-free line folds into the actions cluster as
                 its caption row, so the combined prose under the
                 headline falls from 204-234px to about 156px on
                 every vertical (budget: 186px, 22% of the first
                 viewport). No wording changes. --}}
            {{-- PROSE-GAP floors (copy release): H1 to line mt-4,
                 line to offer mt-3; the offer takes the slim-down
                 wording (Amendment 11). --}}
            <p class="v-line type-lede mt-4 text-text-2">{{ $vertical['line'] }}</p>
            <p class="v-offer type-lede mt-3 font-semibold text-text-1">Free to start. {{ \App\Support\Money::usd($plan->per_referral_cents) }} only when a brand-new customer visits and redeems. Never more than {{ \App\Support\Money::usd($plan->monthly_cap_cents) }} a month in visit charges.</p>
            <div class="mt-5 flex flex-wrap items-center gap-4">
                <a class="btn btn-primary" href="{{ route('register', ['type' => $vertical['type']]) }}">Start free</a>
                <a class="btn btn-secondary" href="#how-it-works">See how it works</a>
                <p class="w-full text-base text-text-2">Your first {{ (int) config('billing.free_visits_at_signup') }} visits are free.</p>
            </div>
        </div>
        @php($visual = $vertical['visual'])
        @if ($visual['kind'] === 'photo')
            <picture>
                <source type="image/webp" srcset="{{ asset($visual['base'].'-480.webp') }} 480w, {{ asset($visual['base'].'-768.webp') }} 768w, {{ asset($visual['base'].'-1080.webp') }} 1080w" sizes="(min-width: 1024px) 640px, 100vw">
                <source type="image/jpeg" srcset="{{ asset($visual['base'].'-480.jpg') }} 480w, {{ asset($visual['base'].'-768.jpg') }} 768w, {{ asset($visual['base'].'-1080.jpg') }} 1080w" sizes="(min-width: 1024px) 640px, 100vw">
                <img src="{{ asset($visual['base'].'-768.jpg') }}" width="{{ $visual['width'] }}" height="{{ $visual['height'] }}" alt="{{ $visual['alt'] }}" loading="lazy" decoding="async" class="mx-auto mt-10 w-full max-w-[640px] rounded-xl border border-line shadow-e2">
            </picture>
        @else
            <picture>
                <source type="image/webp" srcset="{{ asset($visual['base'].'-480.webp') }} 480w" sizes="(min-width: 1024px) 420px, 100vw">
                <img src="{{ asset($visual['base'].'-480.jpg') }}" width="{{ $visual['width'] }}" height="{{ $visual['height'] }}" alt="{{ $visual['alt'] }}" loading="lazy" decoding="async" class="mx-auto mt-10 w-full max-w-[420px] rounded-xl border border-line shadow-e2">
            </picture>
        @endif
    </section>
    {{-- S-04 (release 08b): the objection section answers the
         named worry while it is live, second on the page,
         not fourth after pricing. 08f (contrast audit F-10):
         grounds alternate down the vertical pages too, so this
         section takes the cream ground. --}}
    <section class="bg-surface-1"><div class="shell section-pad">
        <div class="max-w-[640px]">
            <h2 class="type-title-1 text-text-1">{{ $vertical['objection'] }}</h2>
            {{-- PROSE-GAP floor (copy release): H2 to its lede
                 paragraph is never under 16px; mt-4, was mt-3. --}}
            <p class="mt-4 text-lg text-text-2">{{ $vertical['objection_answer'] }}</p>
        </div>
    </div></section>

    <section id="how-it-works" class="bg-surface-0"><div class="shell section-pad">
        <h2 class="type-title-1 text-text-1">How it works</h2>
        <div class="mt-8">
            @include('partials.how-it-works')
        </div>
        </div>
    </section>

    {{-- 08f (contrast audit F-10): pricing and the calculator
         take the cream ground, ending the run of four white
         blocks (pricing, calculator, FAQ, starter kit) the
         audit measured on these pages; the plan card and the
         worksheet card read as objects on the cream. --}}
    <section class="bg-surface-1"><div class="shell section-pad">
        <h2 class="type-title-1 text-center text-text-1">Simple, transparent pricing</h2>
        <div class="mt-8">
            @include('partials.pricing-plan')
        </div>
        <p class="mt-4 text-center text-sm text-text-2">Square handles payments. Twilio handles text messages.</p>
        @include('partials.calculator', ['registerUrl' => route('register', ['type' => $vertical['type']])])
    </div></section>

    <section class="shell section-pad">
        <h2 class="type-title-1 text-text-1">FAQ</h2>
        {{-- PROSE-GAP (copy release): H2 to list mt-8, was mt-6. --}}
        <div class="mt-8 max-w-[640px]">
            @include('partials.faq-list', ['faqs' => $faqs, 'heading' => 'h3'])
        </div>
    </section>

    {{-- 08f (contrast audit F-10): on the verticals the kit
         takes the cream ground (see the partial's $ground),
         completing the alternation; home keeps it white. --}}
    @include('partials.starter-kit', ['source' => 'vertical_'.$vertical['type'], 'ground' => 'bg-surface-1'])

    {{-- Closing band (inventory LAND-C2). R12 (release 08b): the
         band's first line repeated the hero's offer paragraph
         verbatim, the same page's sixth statement of the offer;
         it is deleted. The band is the accent Start free alone,
         the page's single accent action. --}}
    <section class="on-dark bg-ink-950">
        <div class="shell section-pad">
            <div class="mx-auto max-w-[720px] text-center">
            <p><a class="btn btn-accent btn-lg" href="{{ route('register', ['type' => $vertical['type']]) }}">Start free</a></p>
            </div>
        </div>
    </section>

@endsection
