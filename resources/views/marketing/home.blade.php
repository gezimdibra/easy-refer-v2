@extends('layouts.marketing')

@section('title', 'Easy Refer: Turn Your Customers Into Your Best Marketers')

@section('content')
    {{-- Hero (redesign-spec.md Section 10.1 item 2): centered single
         column, eyebrow pill, display H1, lede, one primary and one
         secondary action, a check-icon microcopy row, then the
         product visual. Release 08b touches this block once, whole:
         D-1 restores the reading order on mobile (headline, lede,
         actions, trust row; the release 07 order overrides are
         gone from app.css); the lede is copy Option B verbatim
         (HOME-HERO-02) and the trust row carries the plan-table
         price facts as fragments, P-01; F1 gives mobile a genuine
         portrait dashboard capture in a phone-form frame (below);
         F2/F3 are handled in the consent card and the sticky bar
         script. Desktop keeps the window treatment and is
         otherwise unchanged. --}}
    <section class="bg-surface-0">
        <div class="shell mkt-hero-shell pt-6 lg:pt-20">
            <div class="mkt-hero mx-auto max-w-[880px] text-center">
                <p><span class="eyebrow-pill">Referral marketing for local businesses</span></p>
                <h1 class="mkt-hero-title type-display mt-6 text-text-1">Turn your customers into your best marketers</h1>
                <p class="mkt-hero-lede type-lede mx-auto mt-6 max-w-[56ch] text-text-2">Easy Refer turns your customers into referrers with QR codes, verified visits, and rewards you only pay for when they work. Free to start, and your first {{ (int) config('billing.free_visits_at_signup') }} verified visits are free.</p>
                <div class="mkt-hero-actions mt-8 flex flex-wrap items-center justify-center gap-3">
                    <a class="btn btn-primary btn-lg" href="{{ route('register') }}">Start free</a>
                    <a class="btn btn-secondary btn-lg" href="#how-it-works">See how it works</a>
                </div>
                <ul class="mkt-hero-trust mt-6 flex flex-wrap items-center justify-center gap-x-5 gap-y-2">
                    <li class="type-caption flex items-center gap-2 text-text-2"><x-icon name="check" :size="16" class="text-success-strong" />{{ \App\Support\Money::usd($plan->per_referral_cents) }} per verified referral visit</li>
                    <li class="type-caption flex items-center gap-2 text-text-2"><x-icon name="check" :size="16" class="text-success-strong" />Never more than {{ \App\Support\Money::usd($plan->monthly_cap_cents) }} a month</li>
                    <li class="type-caption flex items-center gap-2 text-text-2"><x-icon name="check" :size="16" class="text-success-strong" />No setup fee</li>
                    <li class="type-caption flex items-center gap-2 text-text-2"><x-icon name="check" :size="16" class="text-success-strong" />Cancel anytime</li>
                </ul>
            </div>

            {{-- F1 (release 08b): below lg the hero visual is a
                 genuine portrait capture of the owner dashboard
                 at phone width (430 CSS px, DPR 3, cropped 9:16),
                 presented as the phone screen itself: no browser
                 chrome, no window bar. The asset is the product
                 the visitor is being sold, in the form they will
                 actually use it. --}}
            <div class="mkt-hero-phone mx-auto max-w-[380px] lg:hidden">
                <div class="overflow-hidden rounded-[2rem] border border-line bg-surface-0 shadow-e2">
                    <picture>
                        <source type="image/webp" srcset="{{ asset('images/dashboard-hero-portrait-860.webp') }} 860w, {{ asset('images/dashboard-hero-portrait-1290.webp') }} 1290w" sizes="100vw">
                        <source type="image/jpeg" srcset="{{ asset('images/dashboard-hero-portrait-860.jpg') }} 860w, {{ asset('images/dashboard-hero-portrait-1290.jpg') }} 1290w" sizes="100vw">
                        <img src="{{ asset('images/dashboard-hero-portrait-1290.jpg') }}" width="1290" height="2293" alt="The Easy Refer dashboard, showing today's referrals and what they are worth." fetchpriority="high" class="block w-full">
                    </picture>
                </div>
            </div>

            <div class="mkt-hero-window mx-auto mt-16 hidden max-w-[1080px] lg:block">
                <div class="window">
                    <div class="window-bar" aria-hidden="true">
                        <span class="window-dot"></span><span class="window-dot"></span><span class="window-dot"></span>
                        <span class="window-url">easy-refer.com/auth</span>
                        <span class="w-10"></span>
                    </div>
                    <picture>
                        <source type="image/webp" srcset="{{ asset('images/dashboard-overview-480.webp') }} 480w, {{ asset('images/dashboard-overview-768.webp') }} 768w, {{ asset('images/dashboard-overview-1080.webp') }} 1080w" sizes="(min-width: 1024px) 1080px, 100vw">
                        <source type="image/jpeg" srcset="{{ asset('images/dashboard-overview-480.jpg') }} 480w, {{ asset('images/dashboard-overview-768.jpg') }} 768w, {{ asset('images/dashboard-overview-1080.jpg') }} 1080w" sizes="(min-width: 1024px) 1080px, 100vw">
                        <img src="{{ asset('images/dashboard-overview-1080.jpg') }}" width="1080" height="810" alt="The Easy Refer dashboard, showing today's referrals and what they are worth." fetchpriority="high" class="block w-full">
                    </picture>
                </div>
            </div>
        </div>
    </section>

    {{-- Proof, part 1 (release 08b, D-2/DG-1): the OD-6 stat set
         as one designed unit, a tinted panel with a lead-in naming
         whose numbers these are, 2x2 on mobile and four across on
         desktop. The numerals keep their scale, weight, and
         tabular figures; only the container changed. All four
         cells render from the single-source claims values, never
         typed here (AP-07). The price figure is not a stat and
         never renders in this band. The real-store photos moved
         to their own section after How It Works (below), so the
         whole mobile proof stretch before the mechanism is now
         this band alone. --}}
    <section class="border-y border-line bg-surface-0">
        <div class="shell py-14 lg:py-20">
            <p class="type-eyebrow text-center text-brand-700">What stores see with Easy Refer</p>
            <div class="mx-auto mt-6 max-w-[1080px] rounded-2xl border border-line bg-surface-1 px-4 py-8 sm:px-8">
                <div class="grid grid-cols-2 gap-y-10 lg:grid-cols-4">
                    <div class="px-2 text-center lg:border-l lg:border-line lg:first:border-l-0">
                        <p class="type-stat text-text-1">{{ config('claims.new_customers_per_year') }}</p>
                        <p class="type-caption mt-2 text-text-2">new customers per year on average</p>
                    </div>
                    <div class="px-2 text-center lg:border-l lg:border-line">
                        <p class="type-stat text-text-1">{{ config('claims.higher_purchase') }}</p>
                        <p class="type-caption mt-2 text-text-2">higher purchase amount</p>
                    </div>
                    <div class="px-2 text-center lg:border-l lg:border-line">
                        <p class="type-stat text-text-1">{{ config('claims.retention_rate') }}</p>
                        <p class="type-caption mt-2 text-text-2">customer retention</p>
                    </div>
                    <div class="px-2 text-center lg:border-l lg:border-line">
                        <p class="type-stat text-text-1">{{ config('claims.customer_satisfaction') }}</p>
                        <p class="type-caption mt-2 text-text-2">satisfaction</p>
                    </div>
                </div>
            </div>

            {{-- C-03 / M-02 (benchmark fix lane): the primary action
                 repeats verbatim after every major proof block, so no
                 scroll position sits far from it. Same pattern closes
                 the photos, how-it-works, benefits, features, and
                 stories sections below. --}}
            <p class="mt-10 text-center"><a class="btn btn-primary btn-lg" href="{{ route('register') }}">Start free</a></p>
        </div>
    </section>
    <section id="how-it-works" class="bg-surface-1">
        <div class="shell section-pad">
            {{-- D-3 (release 08b): the eyebrow repeated this H2
                 verbatim; it is gone, here and on the features,
                 stories, and pricing sections below. --}}
            <h2 class="type-title-1 text-text-1">How it works</h2>
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
                            <img src="{{ asset('images/poster-counter-480.jpg') }}" width="480" height="480" alt="The printed counter poster for Harbor Street Barbers: the business name, a large QR code, and the words Scan this code to join and start referring." loading="lazy" decoding="async" class="block w-full">
                        </picture>
                    </div>
                </figure>
            </div>
            <p class="mt-10 text-center"><a class="btn btn-primary btn-lg" href="{{ route('register') }}">Start free</a></p>
        </div>
    </section>

    {{-- Proof, part 2 (release 08b, D-2/DG-1): the real-store
         photos as their own section after the mechanism, each
         photo paired with its own business (AP-07), mobile crops
         held to about 60% of a viewport. --}}
    <section class="bg-surface-0">
        {{-- R-3 (release 08d): compact bottom step on mobile; the
             Benefits section follows on the same surface. --}}
        <div class="shell section-pad section-pad-compact-b">
            <p class="type-eyebrow text-brand-700">In store now</p>
            <h2 class="type-title-1 mt-3 text-text-1">Running in real stores</h2>
            <p class="type-lede mt-3 max-w-[56ch] text-text-2">Real counters, real customers, and the same printed code your customers would scan.</p>
            <div class="mt-12 grid gap-6 md:grid-cols-3">
                <figure>
                    <picture>
                        <source type="image/webp" srcset="{{ asset('images/photos/ungaro-counter-480.webp') }} 480w, {{ asset('images/photos/ungaro-counter-768.webp') }} 768w, {{ asset('images/photos/ungaro-counter-1020.webp') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                        <source type="image/jpeg" srcset="{{ asset('images/photos/ungaro-counter-480.jpg') }} 480w, {{ asset('images/photos/ungaro-counter-768.jpg') }} 768w, {{ asset('images/photos/ungaro-counter-1020.jpg') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                        <img src="{{ asset('images/photos/ungaro-counter-768.jpg') }}" width="1020" height="1275" alt="A printed Easy Refer referral sign with a QR code on the counter at Ungaro Coal Fired Pizza Cafe." loading="lazy" decoding="async" class="aspect-[5/4] w-full rounded-xl object-cover md:aspect-[4/5]">
                    </picture>
                    <figcaption class="type-caption mt-4 text-text-2">Ungaro Coal Fired Pizza Cafe, with its referral code at the register.</figcaption>
                </figure>
                <figure>
                    <picture>
                        <source type="image/webp" srcset="{{ asset('images/photos/reception-desk-480.webp') }} 480w, {{ asset('images/photos/reception-desk-768.webp') }} 768w, {{ asset('images/photos/reception-desk-1020.webp') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                        <source type="image/jpeg" srcset="{{ asset('images/photos/reception-desk-480.jpg') }} 480w, {{ asset('images/photos/reception-desk-768.jpg') }} 768w, {{ asset('images/photos/reception-desk-1020.jpg') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                        <img src="{{ asset('images/photos/reception-desk-768.jpg') }}" width="1020" height="1230" alt="A printed Easy Refer referral sign with a QR code in an acrylic holder on a clinic reception desk." loading="lazy" decoding="async" class="aspect-[5/4] w-full rounded-xl object-cover md:aspect-[4/5]">
                    </picture>
                    <figcaption class="type-caption mt-4 text-text-2">A clinic reception desk, where patients join at check-in.</figcaption>
                </figure>
                <figure>
                    <picture>
                        <source type="image/webp" srcset="{{ asset('images/photos/pharmacy-counter-480.webp') }} 480w, {{ asset('images/photos/pharmacy-counter-768.webp') }} 768w, {{ asset('images/photos/pharmacy-counter-1020.webp') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                        <source type="image/jpeg" srcset="{{ asset('images/photos/pharmacy-counter-480.jpg') }} 480w, {{ asset('images/photos/pharmacy-counter-768.jpg') }} 768w, {{ asset('images/photos/pharmacy-counter-1020.jpg') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                        <img src="{{ asset('images/photos/pharmacy-counter-768.jpg') }}" width="1020" height="1275" alt="A printed Easy Refer referral poster with a QR code at a pharmacy counter in Albania." loading="lazy" decoding="async" class="aspect-[5/4] w-full rounded-xl object-cover md:aspect-[4/5]">
                    </picture>
                    <figcaption class="type-caption mt-4 text-text-2">A pharmacy counter in Albania, running the same program in Albanian.</figcaption>
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
            <h2 class="type-title-1 mt-3 text-text-1">Why use Easy Refer?</h2>
            <div class="mt-12 grid gap-10 md:grid-cols-3">
                <div>
                    <p class="flex h-11 w-11 items-center justify-center rounded-lg bg-surface-2 text-brand-600"><x-icon name="users" :size="24" /></p>
                    <h3 class="type-title-3 mt-4 text-text-1">Attract new customers</h3>
                    <p class="type-body mt-2 max-w-[68ch] text-text-2">Your happy customers are your best marketing. Easy Refer makes it simple for them to refer friends, with a code to scan and a link to share.</p>
                </div>
                <div>
                    <p class="flex h-11 w-11 items-center justify-center rounded-lg bg-surface-2 text-brand-600"><x-icon name="trending-up" :size="24" /></p>
                    <h3 class="type-title-3 mt-4 text-text-1">Increase revenue</h3>
                    <p class="type-body mt-2 max-w-[68ch] text-text-2">Rewards bring referred friends in, and bulk texts bring your customers back. Both sides earn, so both sides return.</p>
                </div>
                <div>
                    <p class="flex h-11 w-11 items-center justify-center rounded-lg bg-surface-2 text-brand-600"><x-icon name="wallet" :size="24" /></p>
                    <h3 class="type-title-3 mt-4 text-text-1">Affordable pricing</h3>
                    <p class="type-body mt-2 max-w-[68ch] text-text-2">You pay only when a referred friend actually visits. No monthly fee, and a hard monthly cap, so a great month never becomes a big bill.</p>
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
            <h2 class="type-title-1 text-text-1">Powerful features</h2>
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
                    {{-- D-4 (release 08b): the panel title takes the
                         H3 step, the mechanism is one short line,
                         and the safeguards read as a check row, so
                         the panel no longer leads with a wall. Copy
                         is R5 with S-10's opening. --}}
                    <h3 class="type-title-3 text-text-1">QR Code referrals</h3>
                    <p class="type-body mt-3 text-text-2">Print your QR code, put it by the register, and customers join in seconds. Your dashboard shows what each visit was worth.</p>
                    <ul class="mt-3 space-y-2">
                        <li class="type-body-sm flex items-start gap-2 text-text-2"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-success-strong" />Every visit is verified before it counts</li>
                        <li class="type-body-sm flex items-start gap-2 text-text-2"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-success-strong" />Held rewards are reviewed before they pay out</li>
                    </ul>
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
                    <h3 class="type-title-3 text-text-1">SMS marketing</h3>
                    <p class="type-body mt-3 text-text-2">Send bulk text campaigns to customers who opted in. Anyone who replies STOP is opted out automatically.</p>
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
            <h2 class="type-title-1 text-text-1">Success stories</h2>
            <div class="mt-12 grid gap-6 md:grid-cols-2">
                <figure class="rounded-xl border border-line bg-surface-0 shadow-e1" style="padding: 32px">
                    <blockquote class="type-quote text-text-1">We used to spend a lot of money on Google Ads. Now our customers do the marketing for us.</blockquote>
                    <figcaption class="mt-6 flex items-start justify-between gap-4">
                        <span class="flex items-center gap-3">
                            <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-brand-50 text-brand-700" aria-hidden="true"><span class="type-caption" style="font-weight: 650">NR</span></span>
                            <span><span class="type-title-4 block text-text-1">Nolan and Megan Ralston, Owners, Pizzeria</span></span>
                        </span>
                        <span class="type-title-3 max-w-[220px] text-right text-brand-700">150+ New Customers In 3 months</span>
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

    {{-- Release 08b (item 2.2, DG-5): pricing, the calculator,
         and the FAQ are three sibling sections, not one stack
         inside #pricing. The pricing card keeps its accepted
         desktop co-visibility with the calculator heading below
         it; the FAQ section takes the alternating surface. The
         sticky bar's #pricing suppression zone is unchanged. --}}
    <section id="pricing" class="bg-surface-1">
        <div class="shell section-pad">
            <h2 class="type-title-1 text-center text-text-1">Simple, transparent pricing</h2>
            <p class="type-lede mx-auto mt-3 max-w-[56ch] text-center text-text-2">One plan: free to start, {{ \App\Support\Money::usd($plan->per_referral_cents) }} per verified referral visit, never more than {{ \App\Support\Money::usd($plan->monthly_cap_cents) }} a month.</p>
            <div class="mt-12">
                @include('partials.pricing-plan')
            </div>
            <p class="type-caption mt-4 text-center text-text-2">Square handles payments. Twilio handles text messages.</p>
        </div>
    </section>

    <section class="bg-surface-1">
        <div class="shell pb-24">
            @include('partials.calculator')
        </div>
    </section>

    <section class="bg-surface-0">
        {{-- R-3 (release 08d): compact bottom step on mobile; the
             starter kit follows on the same surface. --}}
        <div class="shell section-pad section-pad-compact-b">
            <div class="mx-auto max-w-[760px]">
                <h2 class="type-title-1 text-text-1">Frequently asked questions</h2>
                <div class="mt-6">
                    @include('partials.faq-list', ['faqs' => $faqs, 'heading' => 'h3'])
                </div>
            </div>
        </div>
    </section>

    {{-- S-01 (release 08b): the starter kit renders BEFORE the
         closing band, so the page ends on the band's moment
         and then the footer, not on a second ending. --}}
    @include('partials.starter-kit', ['source' => 'home'])


    {{-- Closing CTA (HOME-CTA-01 with the OD-6 approved body line,
         option (b); HOME-CTA-02 bullets). The band's button is the
         btn-accent component (CF-07). Redesign (Section 10.1 item
         9): centered column on ink-950, reversed lockup, bullets as
         one centered check row with accent checks. --}}
    <section id="closing-cta" class="on-dark bg-ink-950">
        <div class="shell" style="padding-block: 96px">
            <div class="mx-auto max-w-[720px] text-center">
                <p class="flex justify-center"><x-logo variant="reversed" :size="32" /></p>
                <h2 class="type-title-1 mt-8 text-white">Ready to grow your business?</h2>
                <p class="type-lede mt-4 text-dark-muted">Start today and turn word-of-mouth into your growth engine.</p>
                {{-- D-6 (release 08b): the check list is a
                     left-aligned block centered as a whole, one
                     column, so every check sits in the same column
                     and a wrapped line indents under its own text
                     instead of orphaning its check. --}}
                <ul class="mx-auto mt-6 w-fit space-y-2 text-left">
                    <li class="type-caption flex items-start gap-2 text-white"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-accent-400" />Set up in minutes</li>
                    <li class="type-caption flex items-start gap-2 text-white"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-accent-400" />Nothing charged until after your first {{ (int) config('billing.free_visits_at_signup') }} free visits</li>
                    <li class="type-caption flex items-start gap-2 text-white"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-accent-400" />24/7 Support</li>
                </ul>
                <p class="mt-8"><a class="btn btn-accent btn-lg" href="{{ route('register') }}">Start free</a></p>
            </div>
        </div>
    </section>

    {{-- M-02 (benchmark fix lane): on mobile the primary action is
         reachable at every scroll depth. The repeated section CTAs
         cover most of the page, but the photo block and the features
         stretch still leave scroll positions more than one viewport
         from the nearest button, so a sticky bottom action bar
         covers the scroll itself. It appears once the hero actions
         scroll away and hides over the sections that carry their
         own action in view (pricing, the closing band, the starter
         kit) and over the footer, whose links it must never cover.
         F3 (release 08b): the toggle also carries the consent
         condition (A8-03): while the consent prompt is unanswered
         the bar never switches on, so the card and the bar are
         never co-visible; the check that proves it is CV-F3
         (hidden_files/tooling/08b/cv-f3.mjs). Mobile only; the
         desktop header carries the action at all times. The styles
         live in app.css with the rest of the .mkt-* rules. --}}
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
                // F3 / A8-03: consent is a suppression condition
                // like the zones. The banner only renders while no
                // er_consent cookie exists, so its presence in the
                // DOM is the unanswered state; answering it posts
                // and reloads the page without the banner.
                var consentOpen = !!document.querySelector('.consent-card');
                function render() {
                    var on = !consentOpen && !heroSeen && !covered.pricing && !covered.closing && !covered.kit && !covered.footer;
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
