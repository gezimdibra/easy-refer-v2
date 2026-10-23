@extends('layouts.marketing')

@section('title', 'About Us: Easy Refer')

@section('meta_description', 'About Easy Refer: who we are, what we believe, and how our referral platform helps small businesses grow through word of mouth.')

@section('content')
    <section class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <div class="max-w-[720px]">
            <h1 class="text-[32px] leading-[38px] font-bold text-text-1">Empowering Growth Through Referrals</h1>
            <p class="mt-4 text-lg text-text-2">Building stronger businesses through trusted connections.</p>
            <a class="btn btn-primary mt-6" href="{{ route('register') }}">Join Our Network</a>

            <ul class="mt-8 flex flex-wrap gap-2">
                @foreach (['Simple Setup', 'Instant Rewards', 'Real-time Analytics'] as $chip)
                    <li class="pill pill-info">{{ $chip }}</li>
                @endforeach
            </ul>
        </div>
    </section>

    <section class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <div class="max-w-[640px]">
            <h2 class="text-2xl font-bold text-text-1">Who We Are</h2>
            <h3 class="mt-4 text-xl font-semibold text-text-1">Revolutionizing Business Growth</h3>
            {{-- ABOUT-02 in full: the founding claim renders since
                 OD-6 closed 2026-10-23 (AP-07). --}}
            <p class="mt-2 text-base text-text-2">At Easy Refer, we revolutionize how small businesses grow through word-of-mouth marketing. {{ config('claims.founded') }}, we have created a platform that makes referral programs simple, effective, and rewarding for everyone involved.</p>
        </div>
    </section>

    <section class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <div class="grid gap-10 md:grid-cols-2">
            <div>
                <h2 class="text-2xl font-bold text-text-1">Our Mission</h2>
                <p class="mt-3 text-base text-text-2">To create seamless and effective referral programs that empower small businesses to grow sustainably while building lasting relationships with their customers.</p>
                <ul class="mt-4 list-inside list-disc text-base text-text-2">
                    <li>Empower Business Growth</li>
                    <li>Build Customer Loyalty</li>
                    <li>Drive Sustainable Results</li>
                </ul>
            </div>
            <div>
                <h2 class="text-2xl font-bold text-text-1">Our Vision</h2>
                <p class="mt-3 text-base text-text-2">To become the global standard for referral marketing, making it accessible and effective for businesses of all sizes.</p>
                <ul class="mt-4 list-inside list-disc text-base text-text-2">
                    <li>100% Customer Focus</li>
                    <li>24/7 Support</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <h2 class="text-2xl font-bold text-text-1">Core Values</h2>
        <ul class="mt-4 max-w-[720px] list-inside list-disc space-y-1 text-base text-text-2">
            <li>Transparency in relationships</li>
            <li>Continuously improving the platform</li>
            <li>Fostering connections that benefit everyone</li>
            <li>Enabling sustainable business expansion</li>
        </ul>
    </section>

    {{-- Our Impact (ABOUT-07): the OD-6 approved counters,
         rendered from the single-source claims values (AP-07). --}}
    <section class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <h2 class="text-2xl font-bold text-text-1">Our Impact</h2>
        <div class="mt-8 grid gap-8 sm:grid-cols-3">
            <div>
                <p class="text-4xl font-bold text-text-1 tabular-nums">{{ config('claims.businesses') }}</p>
                <p class="mt-1 text-sm text-text-2">Businesses Empowered</p>
            </div>
            <div>
                <p class="text-4xl font-bold text-text-1 tabular-nums">{{ config('claims.successful_referrals') }}</p>
                <p class="mt-1 text-sm text-text-2">Successful Referrals</p>
            </div>
            <div>
                <p class="text-4xl font-bold text-text-1 tabular-nums">{{ config('claims.customer_satisfaction') }}</p>
                <p class="mt-1 text-sm text-text-2">Customer Satisfaction</p>
            </div>
        </div>
    </section>

    <section id="contact" class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <h2 class="text-2xl font-bold text-text-1">Get In Touch</h2>
        <address class="mt-4 text-base not-italic text-text-2">
            <p><a class="font-semibold text-brand-700 underline" href="mailto:info@easy-refer.com">info@easy-refer.com</a></p>
            <p class="mt-1">+1 (833) 803-2400</p>
            <p class="mt-1">104 West 27th Street, New York, NY 10001</p>
        </address>
    </section>
@endsection
