@extends('layouts.marketing')

@section('title', 'Easy Refer: Turn Your Customers Into Your Best Marketers')

@section('content')
    {{-- Hero (redesign-spec.md Section 10.1 item 2): centered single
         column, eyebrow pill, display H1, lede with the offer folded
         in as strong spans, one primary and one secondary action,
         a check-icon microcopy row, then the product window at full
         shell width cropped by the fold on desktop. The window
         capture is re-shot from the redesigned dashboard (Section
         6.1 rule 2).
         MT-08 correction (2026-10-24): on mobile the stack ran so
         tall that the primary action sat below the fold, covered by
         the floating consent card (AP-16). Below lg the column is
         now a flex stack whose actions render directly under the
         headline, ahead of the lede and the check row, and the top
         padding tightens; the DOM order (and the desktop layout) is
         unchanged. The ordering rules live in app.css (graduated
         at the release 07 integration). --}}
    {{-- Benchmark fix lane (2026-10-24): C-05 moves the friction
         row directly under the actions on mobile (it rendered after
         the full lede, below the fold); H-02 sets the mobile
         display size so the headline wraps to three lines instead
         of four; H-04 tightens the mobile top padding and the
         product window margin so the window's top edge enters the
         first viewport. Desktop is untouched: every rule sits
         below lg, and the DOM order is unchanged. All of it lives
         in app.css with the MT-08 rules (graduated at the release
         07 integration). --}}
    <section class="bg-surface-0">
        <div class="shell mkt-hero-shell pt-6 lg:pt-20">
            <div class="mkt-hero mx-auto max-w-[880px] text-center">
                <p><span class="eyebrow-pill">Referral marketing for local businesses</span></p>
                <h1 class="mkt-hero-title type-display mt-6 text-text-1">Turn Your Customers Into Your Best Marketers</h1>
                <p class="mkt-hero-lede type-lede mx-auto mt-6 max-w-[56ch] text-text-2">Easy Refer turns your customers into referrers with QR codes, verified visits, and rewards you only pay for when they work. Free to start. You pay <span class="font-semibold text-text-1">{{ \App\Support\Money::usd($plan->per_referral_cents) }}</span> only when a brand-new customer walks in and redeems. Never more than <span class="font-semibold text-text-1">{{ \App\Support\Money::usd($plan->monthly_cap_cents) }}</span> a month.</p>
                <div class="mkt-hero-actions mt-8 flex flex-wrap items-center justify-center gap-3">
                    <a class="btn btn-primary btn-lg" href="{{ route('register') }}">Start free</a>
                    <a class="btn btn-secondary btn-lg" href="#how-it-works">See how it works</a>
                </div>
                <ul class="mkt-hero-trust mt-6 flex flex-wrap items-center justify-center gap-x-5 gap-y-2">
                    <li class="type-caption flex items-center gap-2 text-text-2"><x-icon name="check" :size="16" class="text-success-strong" />First {{ (int) config('billing.free_visits_at_signup') }} verified visits free</li>
                    <li class="type-caption flex items-center gap-2 text-text-2"><x-icon name="check" :size="16" class="text-success-strong" />No setup fee</li>
                    <li class="type-caption flex items-center gap-2 text-text-2"><x-icon name="check" :size="16" class="text-success-strong" />Cancel anytime</li>
                </ul>
            </div>

            <div class="mkt-hero-window mx-auto mt-16 max-w-[1080px]">
                <div class="window">
                    <div class="window-bar" aria-hidden="true">
                        <span class="window-dot"></span><span class="window-dot"></span><span class="window-dot"></span>
                        <span class="window-url">easy-refer.com/auth</span>
                        <span class="w-10"></span>
                    </div>
                    <picture>
                        {{-- V-02 (benchmark fix lane): below lg the hero
                             shows the phone-form dashboard (a real
                             capture of the owner dashboard at 360px,
                             cropped to the window's 4:3 box), so the
                             product reads as a product on phones
                             instead of a desktop screenshot shrunk to
                             texture. The desktop sources are unchanged. --}}
                        <source media="(max-width: 1023px)" type="image/webp" srcset="{{ asset('images/dashboard-mobile-hero-720.webp') }}" sizes="100vw">
                        <source media="(max-width: 1023px)" srcset="{{ asset('images/dashboard-mobile-hero-720.jpg') }}" sizes="100vw">
                        <source type="image/webp" srcset="{{ asset('images/dashboard-overview-480.webp') }} 480w, {{ asset('images/dashboard-overview-768.webp') }} 768w, {{ asset('images/dashboard-overview-1080.webp') }} 1080w" sizes="(min-width: 1024px) 1080px, 100vw">
                        <source type="image/jpeg" srcset="{{ asset('images/dashboard-overview-480.jpg') }} 480w, {{ asset('images/dashboard-overview-768.jpg') }} 768w, {{ asset('images/dashboard-overview-1080.jpg') }} 1080w" sizes="(min-width: 1024px) 1080px, 100vw">
                        <img src="{{ asset('images/dashboard-overview-1080.jpg') }}" width="1080" height="810" alt="The Easy Refer dashboard, showing today's referrals and what they are worth." fetchpriority="high" class="block w-full">
                    </picture>
                </div>
            </div>
        </div>
    </section>

    {{-- Proof (Section 10.1 item 3): the approved stat set as a
         hairline-divided row, then the real-store photos. All four
         cells are the OD-6 approved stats with their approved
         labels, rendered from the single-source claims values,
         never typed here (AP-07). The price figure is not a stat
         and never renders in this band: it lives in its own
         pricing section below (HOME-PRC-01). The HOME-STAT-01
         price-tile placement was superseded by the OD-6 claims
         set, per the release 05 Gate 3 and visual audit findings
         (corrected 2026-10-24). --}}
    <section class="border-y border-line bg-surface-0">
        <div class="shell section-pad">
            <div class="grid grid-cols-2 gap-y-10 lg:grid-cols-4">
                <div class="px-6 text-center lg:border-l lg:border-line lg:first:border-l-0">
                    <p class="type-stat text-text-1">{{ config('claims.new_customers_per_year') }}</p>
                    <p class="type-caption mt-2 text-text-2">new customers per year on average</p>
                </div>
                <div class="px-6 text-center lg:border-l lg:border-line">
                    <p class="type-stat text-text-1">{{ config('claims.higher_purchase') }}</p>
                    <p class="type-caption mt-2 text-text-2">higher purchase amount</p>
                </div>
                <div class="px-6 text-center lg:border-l lg:border-line">
                    <p class="type-stat text-text-1">{{ config('claims.retention_rate') }}</p>
                    <p class="type-caption mt-2 text-text-2">customer retention</p>
                </div>
                <div class="px-6 text-center lg:border-l lg:border-line">
                    <p class="type-stat text-text-1">{{ config('claims.customer_satisfaction') }}</p>
                    <p class="type-caption mt-2 text-text-2">satisfaction</p>
                </div>
            </div>

            {{-- C-03 / M-02 (benchmark fix lane): the primary action
                 repeats verbatim after every major proof block, so no
                 scroll position sits far from it. Same pattern closes
                 the photos, how-it-works, benefits, features, and
                 stories sections below. --}}
            <p class="mt-10 text-center"><a class="btn btn-primary btn-lg" href="{{ route('register') }}">Start free</a></p>

            <div class="mt-24">
                <p class="type-eyebrow text-brand-700">In store now</p>
                <h2 class="type-title-1 mt-3 text-text-1">Running in real stores</h2>
                <p class="type-lede mt-3 max-w-[56ch] text-text-2">Real counters, real customers, and the same printed code your customers would scan.</p>
                <div class="mt-12 grid gap-6 md:grid-cols-3">
                    <figure>
                        <picture>
                            <source type="image/webp" srcset="{{ asset('images/photos/ungaro-counter-480.webp') }} 480w, {{ asset('images/photos/ungaro-counter-768.webp') }} 768w, {{ asset('images/photos/ungaro-counter-1020.webp') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                            <source type="image/jpeg" srcset="{{ asset('images/photos/ungaro-counter-480.jpg') }} 480w, {{ asset('images/photos/ungaro-counter-768.jpg') }} 768w, {{ asset('images/photos/ungaro-counter-1020.jpg') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                            <img src="{{ asset('images/photos/ungaro-counter-768.jpg') }}" width="1020" height="1275" alt="A printed Easy Refer referral sign with a QR code on the counter at Ungaro Coal Fired Pizza Cafe." loading="lazy" decoding="async" class="aspect-[4/5] w-full rounded-xl object-cover">
                        </picture>
                        <figcaption class="type-caption mt-4 text-text-2">Ungaro Coal Fired Pizza Cafe, with its referral code at the register.</figcaption>
                    </figure>
                    <figure>
                        <picture>
                            <source type="image/webp" srcset="{{ asset('images/photos/reception-desk-480.webp') }} 480w, {{ asset('images/photos/reception-desk-768.webp') }} 768w, {{ asset('images/photos/reception-desk-1020.webp') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                            <source type="image/jpeg" srcset="{{ asset('images/photos/reception-desk-480.jpg') }} 480w, {{ asset('images/photos/reception-desk-768.jpg') }} 768w, {{ asset('images/photos/reception-desk-1020.jpg') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                            <img src="{{ asset('images/photos/reception-desk-768.jpg') }}" width="1020" height="1275" alt="A printed Easy Refer referral sign with a QR code in an acrylic holder on a clinic reception desk." loading="lazy" decoding="async" class="aspect-[4/5] w-full rounded-xl object-cover">
                        </picture>
                        <figcaption class="type-caption mt-4 text-text-2">A clinic reception desk, where patients join at check-in.</figcaption>
                    </figure>
                    <figure>
                        <picture>
                            <source type="image/webp" srcset="{{ asset('images/photos/pharmacy-counter-480.webp') }} 480w, {{ asset('images/photos/pharmacy-counter-768.webp') }} 768w, {{ asset('images/photos/pharmacy-counter-1020.webp') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                            <source type="image/jpeg" srcset="{{ asset('images/photos/pharmacy-counter-480.jpg') }} 480w, {{ asset('images/photos/pharmacy-counter-768.jpg') }} 768w, {{ asset('images/photos/pharmacy-counter-1020.jpg') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                            <img src="{{ asset('images/photos/pharmacy-counter-768.jpg') }}" width="1020" height="1275" alt="A printed Easy Refer referral poster with a QR code at a pharmacy counter in Albania." loading="lazy" decoding="async" class="aspect-[4/5] w-full rounded-xl object-cover">
                        </picture>
                        <figcaption class="type-caption mt-4 text-text-2">A pharmacy counter in Albania, running the same program in Albanian.</figcaption>
                    </figure>
                </div>
                <p class="mt-10 text-center"><a class="btn btn-primary btn-lg" href="{{ route('register') }}">Start free</a></p>
            </div>
        </div>
    </section>

    <section id="how-it-works" class="bg-surface-1">
        <div class="shell section-pad">
            <p class="type-eyebrow text-brand-700">How it works</p>
            <h2 class="type-title-1 mt-3 text-text-1">How It Works</h2>
            <div class="mt-12 grid items-start gap-10 lg:grid-cols-[minmax(0,1fr)_300px]">
                @include('partials.how-it-works', ['stepCols' => 2])
                <figure>
                    <div class="window">
                        <div class="window-bar" aria-hidden="true">
                            <span class="window-dot"></span><span class="window-dot"></span><span class="window-dot"></span>
                            <span class="window-url">Your printed poster</span>
                            <span class="w-10"></span>
                        </div>
                        <picture>
                            <source type="image/webp" srcset="{{ asset('images/poster-counter-480.webp') }} 480w" sizes="300px">
                            <img src="{{ asset('images/poster-counter-480.jpg') }}" width="480" height="480" alt="The printed counter poster for Harbor Street Barbers: the business name, a large QR code, and the words Customers scan this code to join and start referring." loading="lazy" decoding="async" class="block w-full">
                        </picture>
                    </div>
                </figure>
            </div>
            <p class="mt-10 text-center"><a class="btn btn-primary btn-lg" href="{{ route('register') }}">Start free</a></p>
        </div>
    </section>

    {{-- Benefits render as text blocks without card chrome (AP-03);
         the approved stats live in the proof section above.
         Redesign: each column leads with an icon tile (Section
         10.1 item 5). --}}
    <section id="benefits" class="bg-surface-0">
        <div class="shell section-pad">
            <p class="type-eyebrow text-brand-700">Benefits</p>
            <h2 class="type-title-1 mt-3 text-text-1">Why Use Easy Refer?</h2>
            <div class="mt-12 grid gap-10 md:grid-cols-3">
                <div>
                    <p class="flex h-11 w-11 items-center justify-center rounded-lg bg-surface-2 text-brand-600"><x-icon name="users" :size="24" /></p>
                    <h3 class="type-title-3 mt-4 text-text-1">Attract New Customers</h3>
                    <p class="type-body mt-2 max-w-[68ch] text-text-2">Encourage your existing customers to refer their friends. Our platform makes it easy for satisfied customers to spread the word about your business.</p>
                </div>
                <div>
                    <p class="flex h-11 w-11 items-center justify-center rounded-lg bg-surface-2 text-brand-600"><x-icon name="trending-up" :size="24" /></p>
                    <h3 class="type-title-3 mt-4 text-text-1">Increase Revenue</h3>
                    <p class="type-body mt-2 max-w-[68ch] text-text-2">Drive repeat business with smart incentives. Our referral and messaging system encourages both referrers and new customers to keep coming back.</p>
                </div>
                <div>
                    <p class="flex h-11 w-11 items-center justify-center rounded-lg bg-surface-2 text-brand-600"><x-icon name="wallet" :size="24" /></p>
                    <h3 class="type-title-3 mt-4 text-text-1">Affordable Pricing</h3>
                    <p class="type-body mt-2 max-w-[68ch] text-text-2">Only pay for success. Our transparent pricing means you only pay when new customers actually visit your business. Result-based pricing.</p>
                </div>
            </div>
            {{-- S-04: on mobile this section ran about 1,250px with no
                 action and no proof, a dead stretch; it now closes
                 with the primary action like every other block. --}}
            <p class="mt-10 text-center"><a class="btn btn-primary btn-lg" href="{{ route('register') }}">Start free</a></p>
        </div>
    </section>

    <section id="features" class="bg-surface-1">
        <div class="shell section-pad">
            <p class="type-eyebrow text-brand-700">Features</p>
            <h2 class="type-title-1 mt-3 text-text-1">Powerful Features</h2>
            <div class="mt-12 grid gap-6 lg:grid-cols-2">
                {{-- M-01 (benchmark fix lane): min-w-0 lets the grid
                     item shrink to the column. Without it the item's
                     automatic minimum is the window bar's flex
                     min-content (about 337px: the nowrap URL pill,
                     dots, and spacer), which blew the card out to
                     405px at a 360px viewport and set the document
                     scrollWidth to 425. The pill takes min-w-0 too,
                     so it truncates inside its bar like browser
                     chrome instead of forcing the layout wider. --}}
                <div class="min-w-0 rounded-xl border border-line bg-surface-0 shadow-e1" style="padding: 32px">
                    <h3 class="type-title-2 text-text-1">QR Code Referrals</h3>
                    <p class="type-body-lg mt-3 text-text-2">Generate Referral Codes for your business in seconds. Every visit is verified before it counts, held rewards are reviewed before they pay out, and your dashboard shows what your visits were worth.</p>
                    <div class="window mt-6" style="border-radius: var(--radius-lg)">
                        <div class="window-bar" aria-hidden="true">
                            <span class="window-dot"></span><span class="window-dot"></span><span class="window-dot"></span>
                            <span class="window-url min-w-0">easy-refer.com/auth/qr-code</span>
                            <span class="w-10"></span>
                        </div>
                        <picture>
                            <source type="image/webp" srcset="{{ asset('images/feature-qr-480.webp') }} 480w, {{ asset('images/feature-qr-768.webp') }} 768w" sizes="(min-width: 768px) 50vw, 100vw">
                            <img src="{{ asset('images/feature-qr-768.jpg') }}" width="768" height="425" alt="The QR code page in the owner dashboard, with the code customers scan and the download options for the counter." loading="lazy" decoding="async" class="block w-full">
                        </picture>
                    </div>
                </div>
                <div class="min-w-0 rounded-xl border border-line bg-surface-0 shadow-e1" style="padding: 32px">
                    <h3 class="type-title-2 text-text-1">SMS Marketing</h3>
                    <p class="type-body-lg mt-3 text-text-2">Send SMS campaigns to your customers who agreed to get them. Messages go only to customers who said yes, and anyone who replies STOP is opted out automatically.</p>
                    <div class="window mt-6" style="border-radius: var(--radius-lg)">
                        <div class="window-bar" aria-hidden="true">
                            <span class="window-dot"></span><span class="window-dot"></span><span class="window-dot"></span>
                            <span class="window-url min-w-0">easy-refer.com/auth/customers</span>
                            <span class="w-10"></span>
                        </div>
                        <picture>
                            <source type="image/webp" srcset="{{ asset('images/feature-customers-480.webp') }} 480w, {{ asset('images/feature-customers-768.webp') }} 768w" sizes="(min-width: 768px) 50vw, 100vw">
                            <img src="{{ asset('images/feature-customers-768.jpg') }}" width="768" height="540" alt="The customers list in the owner dashboard, showing which customers agreed to get text messages." loading="lazy" decoding="async" class="block w-full">
                        </picture>
                    </div>
                </div>
            </div>
            <p class="mt-10 text-center"><a class="btn btn-primary btn-lg" href="{{ route('register') }}">Start free</a></p>
        </div>
    </section>

    {{-- Success Stories (HOME-SUC-01, HOME-SUC-02): the OD-6
         approved set, verbatim. No photo attaches to either card:
         no owner-supplied photo depicts either business (AP-07).
         Redesign: quote panels at radius-xl with an initials
         medallion and the result figure in brand-700 title-3 with
         its caption under it (never styled as a link). --}}
    <section id="success-stories" class="bg-surface-0">
        <div class="shell section-pad">
            <p class="type-eyebrow text-brand-700">Success stories</p>
            <h2 class="type-title-1 mt-3 text-text-1">Success Stories</h2>
            <div class="mt-12 grid gap-6 md:grid-cols-2">
                <figure class="rounded-xl border border-line bg-surface-0 shadow-e1" style="padding: 32px">
                    <blockquote class="type-quote text-text-1">We used to spend a lot of money on Google Ads. Now our customers do the marketing for us.</blockquote>
                    <figcaption class="mt-6 flex items-start justify-between gap-4">
                        <span class="flex items-center gap-3">
                            <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-brand-50 text-brand-700" aria-hidden="true"><span class="type-caption" style="font-weight: 650">NR</span></span>
                            <span><span class="type-title-4 block text-text-1">Nolan and Megan Ralston, Owners, Pizzeria</span></span>
                        </span>
                        <span class="type-title-3 max-w-[220px] text-right text-brand-700">150+ New Customers In 3 Months</span>
                    </figcaption>
                </figure>
                <figure class="rounded-xl border border-line bg-surface-0 shadow-e1" style="padding: 32px">
                    <blockquote class="type-quote text-text-1">I would have used Easy Refer since day 1 if it was available.</blockquote>
                    <figcaption class="mt-6 flex items-start justify-between gap-4">
                        <span class="flex items-center gap-3">
                            <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-brand-50 text-brand-700" aria-hidden="true"><span class="type-caption" style="font-weight: 650">TH</span></span>
                            <span><span class="type-title-4 block text-text-1">Talia Hollis, Hairstylist</span></span>
                        </span>
                        <span class="type-title-3 max-w-[220px] text-right text-brand-700">110+ New Customers In 4 months</span>
                    </figcaption>
                </figure>
            </div>
            <p class="mt-10 text-center"><a class="btn btn-primary btn-lg" href="{{ route('register') }}">Start free</a></p>
        </div>
    </section>

    <section id="pricing" class="bg-surface-1">
        <div class="shell section-pad">
            <p class="type-eyebrow text-center text-brand-700">Pricing</p>
            <h2 class="type-title-1 mt-3 text-center text-text-1">Simple, Transparent Pricing</h2>
            <p class="type-lede mx-auto mt-3 max-w-[56ch] text-center text-text-2">One plan: free to start, {{ \App\Support\Money::usd($plan->per_referral_cents) }} per verified referral visit, never more than {{ \App\Support\Money::usd($plan->monthly_cap_cents) }} a month.</p>
            <div class="mt-12">
                @include('partials.pricing-plan')
            </div>
            <p class="type-caption mt-4 text-center text-text-2">Payments are handled by Square. Text messages are handled by Twilio.</p>
            @include('partials.calculator')
            <div class="mx-auto mt-16 max-w-[760px]">
                <h3 class="type-title-1 text-text-1">Frequently asked questions</h3>
                <div class="mt-6">
                    @include('partials.faq-list', ['faqs' => $faqs, 'heading' => 'h4'])
                </div>
            </div>
        </div>
    </section>

    {{-- Closing CTA (HOME-CTA-01 with the OD-6 approved body line,
         option (b); HOME-CTA-02 bullets). The band's button is the
         btn-accent component (CF-07). Redesign (Section 10.1 item
         9): centered column on ink-950, reversed lockup, bullets as
         one centered check row with accent checks. --}}
    <section id="closing-cta" class="on-dark bg-ink-950">
        <div class="shell" style="padding-block: 96px">
            <div class="mx-auto max-w-[720px] text-center">
                <p class="flex justify-center"><x-logo variant="reversed" :size="32" /></p>
                <h2 class="type-title-1 mt-8 text-white">Ready to Grow Your Business?</h2>
                <p class="type-lede mt-4 text-dark-muted">Start today and turn word-of-mouth into your growth engine.</p>
                <ul class="mt-6 flex flex-wrap items-center justify-center gap-x-5 gap-y-2">
                    <li class="type-caption flex items-center gap-2 text-white"><x-icon name="check" :size="16" class="text-accent-400" />Set up in minutes</li>
                    <li class="type-caption flex items-center gap-2 text-white"><x-icon name="check" :size="16" class="text-accent-400" />Nothing charged until after your first {{ (int) config('billing.free_visits_at_signup') }} free visits</li>
                    <li class="type-caption flex items-center gap-2 text-white"><x-icon name="check" :size="16" class="text-accent-400" />24/7 Support</li>
                </ul>
                <p class="mt-8"><a class="btn btn-accent btn-lg" href="{{ route('register') }}">Start free</a></p>
            </div>
        </div>
    </section>

    @include('partials.starter-kit', ['source' => 'home'])

    {{-- M-02 (benchmark fix lane): on mobile the primary action is
         reachable at every scroll depth. The repeated section CTAs
         cover most of the page, but the photo block and the features
         stretch still leave scroll positions more than one viewport
         from the nearest button, so a sticky bottom action bar
         covers the scroll itself. It appears once the hero actions
         scroll away and hides over the sections that carry their
         own action in view (pricing, the closing band, the starter
         kit) and over the footer, whose links it must never cover.
         The consent card (z-40) floats above it until answered,
         which is the intended order. Mobile only; the desktop
         header carries the action at all times. The styles live in
         the hero's scoped block above and graduate into app.css
         with the rest of the .mkt-* rules (crosslane log). --}}
    <div class="mkt-sticky-cta" id="mkt-sticky-cta">
        <a class="btn btn-primary btn-lg w-full" href="{{ route('register') }}">Start free</a>
    </div>
    <script>
        (function () {
            function init() {
                var bar = document.getElementById('mkt-sticky-cta');
                if (!bar || !('IntersectionObserver' in window)) {
                    return;
                }
                var heroSeen = true;
                var covered = { pricing: false, closing: false, kit: false, footer: false };
                function render() {
                    var on = !heroSeen && !covered.pricing && !covered.closing && !covered.kit && !covered.footer;
                    bar.classList.toggle('is-on', on);
                }
                function watch(el, fn) {
                    if (el) {
                        new IntersectionObserver(function (entries) {
                            fn(entries[0].isIntersecting);
                            render();
                        }, { threshold: 0 }).observe(el);
                    }
                }
                watch(document.querySelector('.mkt-hero-actions'), function (v) { heroSeen = v; });
                watch(document.getElementById('pricing'), function (v) { covered.pricing = v; });
                watch(document.getElementById('closing-cta'), function (v) { covered.closing = v; });
                watch(document.getElementById('starter-kit'), function (v) { covered.kit = v; });
                watch(document.querySelector('footer'), function (v) { covered.footer = v; });
            }
            // Deferred to DOMContentLoaded: this script sits inside
            // main, and the footer it watches is parsed after it.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
    </script>
@endsection
