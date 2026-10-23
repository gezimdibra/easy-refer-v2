@extends('layouts.marketing')

@section('title', 'Easy Refer: Turn Your Customers Into Your Best Marketers')

@section('content')
    {{-- Hero (landing-page-spec.md Section 4.1): promise, supporting
         line, offer line, actions, friction microcopy, in the first
         viewport (AP-04). The visual is the real owner dashboard
         (Section 6.3 asset rule, AP-05), a compressed derivative of
         the Sprint 3 capture, served beside the text on desktop and
         directly under the band on mobile so the band itself ends
         inside the 360x740 viewport. --}}
    {{-- Mobile hero spacing is deliberately tighter (top padding
         and the three gaps) so the primary CTA clears the fixed
         consent banner at 360x740 (AP-16) while the band still
         ends inside the viewport (AP-04); the full rhythm pattern
         applies from md up. --}}
    <section class="mx-auto max-w-[1120px] px-5 pt-4 pb-8 md:py-12 lg:py-16 xl:py-20">
        <div class="grid items-center gap-10 lg:grid-cols-2">
            <div>
                <h1 class="text-[32px] leading-[38px] font-bold text-text-1 md:text-[40px] md:leading-[44px]">Turn Your Customers Into Your Best Marketers</h1>
                <p class="mt-2 text-lg text-text-2 md:mt-4">Easy Refer turns your customers into referrers with QR codes, verified visits, and rewards you only pay for when they work.</p>
                <p class="mt-2 text-lg font-semibold text-text-1 md:mt-3">Free to start. You pay {{ \App\Support\Money::usd($plan->per_referral_cents) }} only when a brand-new customer walks in and redeems. Never more than {{ \App\Support\Money::usd($plan->monthly_cap_cents) }} a month.</p>
                <div class="mt-4 flex flex-wrap items-center gap-4 md:mt-6">
                    <a class="btn btn-primary" href="{{ route('register') }}">Start free</a>
                    <a class="btn btn-ghost" href="#how-it-works">See how it works</a>
                </div>
                <p class="mt-3 text-sm text-text-2">Your first {{ (int) config('billing.free_visits_at_signup') }} verified visits are free. No setup fee. Cancel anytime.</p>
            </div>
            {{-- The desktop hero picture is media-gated to lg and
                 its img src is a 1px placeholder: without the gate,
                 browsers download the desktop candidate on mobile
                 too, where the picture is display:none (measured
                 21 KB wasted per mobile visit). On lg the gated
                 sources match and the real screenshot loads eager
                 with high priority; below lg nothing is fetched. --}}
            <picture class="hidden lg:block">
                <source type="image/webp" media="(min-width: 1024px)" srcset="{{ asset('images/dashboard-overview-480.webp') }} 480w, {{ asset('images/dashboard-overview-768.webp') }} 768w, {{ asset('images/dashboard-overview-1080.webp') }} 1080w" sizes="520px">
                <source type="image/jpeg" media="(min-width: 1024px)" srcset="{{ asset('images/dashboard-overview-480.jpg') }} 480w, {{ asset('images/dashboard-overview-768.jpg') }} 768w, {{ asset('images/dashboard-overview-1080.jpg') }} 1080w" sizes="520px">
                <img src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" width="1080" height="810" alt="The Easy Refer dashboard, showing today's referrals and what they are worth." fetchpriority="high" class="w-full rounded-lg border border-line shadow-e2">
            </picture>
        </div>
    </section>

    <div class="mx-auto max-w-[1120px] px-5 pb-8 lg:hidden">
        <picture>
            <source type="image/webp" srcset="{{ asset('images/dashboard-overview-480.webp') }} 480w, {{ asset('images/dashboard-overview-768.webp') }} 768w" sizes="100vw">
            <source type="image/jpeg" srcset="{{ asset('images/dashboard-overview-480.jpg') }} 480w, {{ asset('images/dashboard-overview-768.jpg') }} 768w" sizes="100vw">
            <img src="{{ asset('images/dashboard-overview-768.jpg') }}" width="1080" height="810" alt="The Easy Refer dashboard, showing today's referrals and what they are worth." loading="lazy" decoding="async" class="w-full rounded-lg border border-line shadow-e2">
        </picture>
    </div>

    {{-- Proof (Section 4.2): the OD-6 approved set as the second
         screen. Figures render from the single-source claims values
         and the plan table, never typed here (AP-07). --}}
    <section class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <div class="grid grid-cols-2 gap-8 lg:grid-cols-4">
            <div>
                <p class="text-4xl font-bold text-text-1 tabular-nums">{{ config('claims.new_customers_per_year') }}</p>
                <p class="mt-1 text-sm text-text-2">new customers per year on average</p>
            </div>
            <div>
                <p class="text-4xl font-bold text-text-1 tabular-nums">{{ config('claims.higher_purchase') }}</p>
                <p class="mt-1 text-sm text-text-2">higher purchase</p>
            </div>
            <div>
                <p class="text-4xl font-bold text-text-1 tabular-nums">{{ config('claims.retention_rate') }}</p>
                <p class="mt-1 text-sm text-text-2">retention rate</p>
            </div>
            <div>
                <p class="text-4xl font-bold text-text-1 tabular-nums">{{ \App\Support\Money::usd($plan->per_referral_cents) }}</p>
                <p class="mt-1 text-sm text-text-2">per verified visit, and never more than {{ \App\Support\Money::usd($plan->monthly_cap_cents) }} a month</p>
            </div>
        </div>

        <h2 class="mt-16 text-2xl font-bold text-text-1">Running in real stores</h2>
        <p class="mt-2 text-lg text-text-2">Real counters, real customers, and the same printed code your customers would scan.</p>
        <div class="mt-8 grid gap-8 md:grid-cols-3">
            <figure>
                <picture>
                    <source type="image/webp" srcset="{{ asset('images/photos/ungaro-counter-480.webp') }} 480w, {{ asset('images/photos/ungaro-counter-768.webp') }} 768w, {{ asset('images/photos/ungaro-counter-1020.webp') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                    <source type="image/jpeg" srcset="{{ asset('images/photos/ungaro-counter-480.jpg') }} 480w, {{ asset('images/photos/ungaro-counter-768.jpg') }} 768w, {{ asset('images/photos/ungaro-counter-1020.jpg') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                    <img src="{{ asset('images/photos/ungaro-counter-768.jpg') }}" width="1020" height="1275" alt="A printed Easy Refer referral sign with a QR code on the counter at Ungaro Coal Fired Pizza Cafe." loading="lazy" decoding="async" class="aspect-[4/5] w-full rounded-lg object-cover shadow-e1">
                </picture>
                <figcaption class="mt-3 text-sm text-text-2">Ungaro Coal Fired Pizza Cafe, with its referral code at the register.</figcaption>
            </figure>
            <figure>
                <picture>
                    <source type="image/webp" srcset="{{ asset('images/photos/reception-desk-480.webp') }} 480w, {{ asset('images/photos/reception-desk-768.webp') }} 768w, {{ asset('images/photos/reception-desk-1020.webp') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                    <source type="image/jpeg" srcset="{{ asset('images/photos/reception-desk-480.jpg') }} 480w, {{ asset('images/photos/reception-desk-768.jpg') }} 768w, {{ asset('images/photos/reception-desk-1020.jpg') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                    <img src="{{ asset('images/photos/reception-desk-768.jpg') }}" width="1020" height="1275" alt="A printed Easy Refer referral sign with a QR code in an acrylic holder on a clinic reception desk." loading="lazy" decoding="async" class="aspect-[4/5] w-full rounded-lg object-cover shadow-e1">
                </picture>
                <figcaption class="mt-3 text-sm text-text-2">A clinic reception desk, where patients join at check-in.</figcaption>
            </figure>
            <figure>
                <picture>
                    <source type="image/webp" srcset="{{ asset('images/photos/pharmacy-counter-480.webp') }} 480w, {{ asset('images/photos/pharmacy-counter-768.webp') }} 768w, {{ asset('images/photos/pharmacy-counter-1020.webp') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                    <source type="image/jpeg" srcset="{{ asset('images/photos/pharmacy-counter-480.jpg') }} 480w, {{ asset('images/photos/pharmacy-counter-768.jpg') }} 768w, {{ asset('images/photos/pharmacy-counter-1020.jpg') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                    <img src="{{ asset('images/photos/pharmacy-counter-768.jpg') }}" width="1020" height="1275" alt="A printed Easy Refer referral poster with a QR code at a pharmacy counter in Albania." loading="lazy" decoding="async" class="aspect-[4/5] w-full rounded-lg object-cover shadow-e1">
                </picture>
                <figcaption class="mt-3 text-sm text-text-2">A pharmacy counter in Albania, running the same program in Albanian.</figcaption>
            </figure>
        </div>
    </section>

    <section id="how-it-works" class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <h2 class="text-2xl font-bold text-text-1">How It Works</h2>
        <div class="mt-8 grid items-start gap-10 lg:grid-cols-[minmax(0,1fr)_300px]">
            @include('partials.how-it-works', ['stepCols' => 2])
            <figure>
                <picture>
                    <source type="image/webp" srcset="{{ asset('images/poster-counter-480.webp') }} 480w" sizes="300px">
                    <img src="{{ asset('images/poster-counter-480.jpg') }}" width="480" height="480" alt="The printed counter poster for Harbor Street Barbers: the business name, a large QR code, and the words Customers scan this code to join and start referring." loading="lazy" decoding="async" class="w-full rounded-lg border border-line shadow-e1">
                </picture>
            </figure>
        </div>
    </section>

    {{-- Benefits render as text blocks without card chrome (AP-03);
         the approved stats live in the proof section above. --}}
    <section id="benefits" class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
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

    <section id="features" class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <h2 class="text-2xl font-bold text-text-1">Powerful Features</h2>
        <div class="mt-8 grid gap-8 md:grid-cols-2">
            <div class="card">
                <h3 class="text-xl font-semibold text-text-1">QR Code Referrals</h3>
                <p class="mt-2 text-base text-text-2">Generate Referral Codes for your business in seconds. Every visit is verified before it counts, held rewards are reviewed before they pay out, and your dashboard shows what your visits were worth.</p>
                <picture>
                    <source type="image/webp" srcset="{{ asset('images/feature-qr-480.webp') }} 480w, {{ asset('images/feature-qr-768.webp') }} 768w" sizes="(min-width: 768px) 50vw, 100vw">
                    <img src="{{ asset('images/feature-qr-768.jpg') }}" width="768" height="425" alt="The QR code page in the owner dashboard, with the code customers scan and the download options for the counter." loading="lazy" decoding="async" class="mt-5 w-full rounded-md border border-line">
                </picture>
            </div>
            <div class="card">
                <h3 class="text-xl font-semibold text-text-1">SMS Marketing</h3>
                <p class="mt-2 text-base text-text-2">Send SMS campaigns to your customers who agreed to get them. Messages go only to customers who said yes, and anyone who replies STOP is opted out automatically.</p>
                <picture>
                    <source type="image/webp" srcset="{{ asset('images/feature-customers-480.webp') }} 480w, {{ asset('images/feature-customers-768.webp') }} 768w" sizes="(min-width: 768px) 50vw, 100vw">
                    <img src="{{ asset('images/feature-customers-768.jpg') }}" width="768" height="540" alt="The customers list in the owner dashboard, showing which customers agreed to get text messages." loading="lazy" decoding="async" class="mt-5 w-full rounded-md border border-line">
                </picture>
            </div>
        </div>
    </section>

    {{-- Success Stories (HOME-SUC-01, HOME-SUC-02): the OD-6
         approved set, verbatim. No photo attaches to either card:
         no owner-supplied photo depicts either business (AP-07). --}}
    <section id="success-stories" class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <h2 class="text-2xl font-bold text-text-1">Success Stories</h2>
        <div class="mt-8 grid gap-8 md:grid-cols-2">
            <figure class="card">
                <blockquote class="text-lg text-text-1">We used to spend a lot of money on Google Ads. Now our customers do the marketing for us.</blockquote>
                <figcaption class="mt-4">
                    <p class="font-semibold text-text-1">Nolan and Megan Ralston, Owners, Pizzeria</p>
                    <p class="mt-1 font-semibold text-brand-700">150+ New Customers In 3 Months</p>
                </figcaption>
            </figure>
            <figure class="card">
                <blockquote class="text-lg text-text-1">I would have used Easy Refer since day 1 if it was available.</blockquote>
                <figcaption class="mt-4">
                    <p class="font-semibold text-text-1">Talia Hollis, Hairstylist</p>
                    <p class="mt-1 font-semibold text-brand-700">110+ New Customers In 4 months</p>
                </figcaption>
            </figure>
        </div>
    </section>

    <section id="pricing" class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
        <h2 class="text-center text-2xl font-bold text-text-1">Simple, Transparent Pricing</h2>
        <p class="mt-2 text-center text-lg text-text-2">One plan: free to start, {{ \App\Support\Money::usd($plan->per_referral_cents) }} per verified referral visit, never more than {{ \App\Support\Money::usd($plan->monthly_cap_cents) }} a month.</p>
        <div class="mt-8">
            @include('partials.pricing-plan')
        </div>
        <p class="mt-4 text-center text-sm text-text-2">Payments are handled by Square. Text messages are handled by Twilio.</p>
        @include('partials.calculator')
        <div class="mx-auto mt-10 max-w-[640px]">
            @include('partials.faq-list', ['faqs' => $faqs, 'heading' => 'h3'])
        </div>
    </section>

    {{-- Closing CTA (HOME-CTA-01 with the OD-6 approved body line,
         option (b); HOME-CTA-02 bullets). The band's button is the
         btn-accent component (CF-07). --}}
    <section class="on-dark bg-ink-900">
        <div class="mx-auto max-w-[1120px] px-5 py-8 md:py-12 lg:py-16 xl:py-20">
            <h2 class="text-2xl font-bold text-white">Ready to Grow Your Business?</h2>
            <p class="mt-3 text-lg text-white">Start today and turn word-of-mouth into your growth engine.</p>
            <ul class="mt-4 space-y-1 text-base text-white">
                <li>Set up in minutes</li>
                <li>Nothing charged until after your first {{ (int) config('billing.free_visits_at_signup') }} free visits</li>
                <li>24/7 Support</li>
            </ul>
            <a class="btn btn-accent mt-6" href="{{ route('register') }}">Start free</a>
        </div>
    </section>

    @include('partials.starter-kit', ['source' => 'home'])
@endsection
