@extends('layouts.marketing')

@section('title', 'About Us: Easy Refer')

@section('meta_description', 'About Easy Refer: who we are, what we believe, and how our referral platform helps small businesses grow through word of mouth.')

@section('content')
    <section class="shell section-pad">
        <div class="max-w-[720px]">
            {{-- R14/R15/R16 (release 08b): the H1 names the
                 audience and the mechanism, the subhead carries
                 the pay-for-results rule, and the CTA is the
                 site-wide Start free. The three promise chips are
                 deleted (R17's scope): "Instant Rewards" also
                 contradicted the vesting model, since rewards
                 vest after a hold, they are never instant. --}}
            <h1 class="type-title-1 text-text-1">We help small businesses grow through referrals</h1>
            <p class="mt-4 text-lg text-text-2">Word of mouth, made simple: customers share, friends visit, and you pay only for results.</p>
            <a class="btn btn-primary mt-6" href="{{ route('register') }}">Start free</a>
        </div>
    </section>

    <section class="shell section-pad">
        <div class="max-w-[640px]">
            <h2 class="type-title-1 text-text-1">Who we are</h2>
            <h3 class="mt-4 type-title-3 text-text-1">Revolutionizing business growth</h3>
            {{-- ABOUT-02 (release 08b, R17 with S-15): the honest
                 replacement. The old "we revolutionize" body
                 carried the banned inflation phrasing and is gone;
                 the founding fact renders from the claims config
                 (OD-6, AP-07). --}}
            <p class="mt-2 text-base text-text-2">Easy Refer makes word-of-mouth marketing simple for small businesses. {{ config('claims.founded') }}, Easy Refer was built on one rule: you only pay when a referral works.</p>

            {{-- X-5 correction (2026-10-24): the About page carried
                 no photo at all. One owner-cleared real store photo
                 with its own business caption, in the same figure
                 treatment as the home page proof strip. --}}
            <figure class="mt-10">
                <picture>
                    <source type="image/webp" srcset="{{ asset('images/photos/ungaro-counter-480.webp') }} 480w, {{ asset('images/photos/ungaro-counter-768.webp') }} 768w, {{ asset('images/photos/ungaro-counter-1020.webp') }} 1020w" sizes="(min-width: 768px) 640px, 100vw">
                    <source type="image/jpeg" srcset="{{ asset('images/photos/ungaro-counter-480.jpg') }} 480w, {{ asset('images/photos/ungaro-counter-768.jpg') }} 768w, {{ asset('images/photos/ungaro-counter-1020.jpg') }} 1020w" sizes="(min-width: 768px) 640px, 100vw">
                    <img src="{{ asset('images/photos/ungaro-counter-768.jpg') }}" width="1020" height="1275" alt="A printed Easy Refer referral sign with a QR code on the counter at Ungaro Coal Fired Pizza Cafe." loading="lazy" decoding="async" class="aspect-[4/5] w-full rounded-xl object-cover">
                </picture>
                <figcaption class="type-caption mt-4 text-text-2">Ungaro Coal Fired Pizza Cafe, with its referral code at the register.</figcaption>
            </figure>
        </div>
    </section>

    <section class="shell section-pad">
        <div class="grid gap-10 md:grid-cols-2">
            <div>
                <h2 class="type-title-1 text-text-1">Our mission</h2>
                <p class="mt-3 text-base text-text-2">Our mission is to make referral programs simple and effective for small businesses, so they can grow through their customers instead of paying for ads.</p>
                <ul class="mt-4 list-inside list-disc text-base text-text-2">
                    <li>Empower Business Growth</li>
                    <li>Build Customer Loyalty</li>
                    <li>Drive Sustainable Results</li>
                </ul>
            </div>
            <div>
                <h2 class="type-title-1 text-text-1">Our vision</h2>
                <p class="mt-3 text-base text-text-2">Our vision is a world where a small business's best marketing is a happy customer, and where referral programs are within reach of every business, not just the big ones.</p>
                <ul class="mt-4 list-inside list-disc text-base text-text-2">
                    <li>100% Customer Focus</li>
                    <li>24/7 Support</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="shell section-pad">
        <h2 class="type-title-1 text-text-1">Core values</h2>
        {{-- S-11 (release 08b): all four values in one grammatical
             form (noun phrases), matching the first item's. --}}
        <ul class="mt-4 max-w-[720px] list-inside list-disc space-y-1 text-base text-text-2">
            <li>Transparency in relationships</li>
            <li>Continuous improvement of the platform</li>
            <li>Connections that benefit everyone</li>
            <li>Sustainable business expansion</li>
        </ul>
    </section>

    {{-- Our Impact (ABOUT-07): the OD-6 approved counters,
         rendered from the single-source claims values (AP-07). --}}
    <section class="shell section-pad">
        <h2 class="type-title-1 text-text-1">Our impact</h2>
        <div class="mt-8 grid gap-8 sm:grid-cols-3">
            <div>
                <p class="type-stat text-text-1">{{ config('claims.businesses') }}</p>
                <p class="mt-1 text-sm text-text-2">Businesses Empowered</p>
            </div>
            <div>
                <p class="type-stat text-text-1">{{ config('claims.successful_referrals') }}</p>
                <p class="mt-1 text-sm text-text-2">Successful Referrals</p>
            </div>
            <div>
                <p class="type-stat text-text-1">{{ config('claims.customer_satisfaction') }}</p>
                <p class="mt-1 text-sm text-text-2">Customer Satisfaction</p>
            </div>
        </div>
    </section>

    {{-- S-07 (release 08b): the About page gains the home closing
         band, verbatim (HOME-CTA-01/02), between Our Impact and
         Get In Touch. Its accent Start free is this page's single
         accent action. --}}
    <section class="on-dark bg-ink-950">
        <div class="shell" style="padding-block: 96px">
            <div class="mx-auto max-w-[720px] text-center">
                <p class="flex justify-center"><x-logo variant="reversed" :size="32" /></p>
                <h2 class="type-title-1 mt-8 text-white">Ready to grow your business?</h2>
                <p class="type-lede mt-4 text-dark-muted">Start today and turn word-of-mouth into your growth engine.</p>
                <ul class="mx-auto mt-6 w-fit space-y-2 text-left">
                    <li class="type-caption flex items-start gap-2 text-white"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-accent-400" />Set up in minutes</li>
                    <li class="type-caption flex items-start gap-2 text-white"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-accent-400" />Nothing charged until after your first {{ (int) config('billing.free_visits_at_signup') }} free visits</li>
                    <li class="type-caption flex items-start gap-2 text-white"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-accent-400" />24/7 Support</li>
                </ul>
                <p class="mt-8"><a class="btn btn-accent btn-lg" href="{{ route('register') }}">Start free</a></p>
            </div>
        </div>
    </section>

    <section id="contact" class="shell section-pad">
        <h2 class="type-title-1 text-text-1">Get in touch</h2>
        <address class="mt-4 text-base not-italic text-text-2">
            <p><a class="font-semibold text-brand-700 underline" href="mailto:info@easy-refer.com">info@easy-refer.com</a></p>
            <p class="mt-1">+1 (833) 803-2400</p>
            <p class="mt-1">104 West 27th Street, New York, NY 10001</p>
        </address>
    </section>
@endsection
