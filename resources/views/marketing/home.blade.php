@extends('layouts.marketing')

@section('title', 'Easy Refer: Get Your Customers to Refer Their Friends')

{{-- Light lock (owner directive, 2026-10-24): the landing page
     renders the light theme at any device setting. The layout
     turns this section into the .landing-light-lock scope in
     app.css; no other page sets it. --}}
@section('theme_lock', 'light')

@section('content')
    {{-- A flashed status (e.g. a customer bounced here from a
         closed flow step, T4-F4) renders in the notices region;
         with no flash this block renders nothing at all. --}}
    @if (session('status'))
        <div class="shell pt-6">
            @include('partials.notices', ['summaryOnly' => true])
        </div>
    @endif

    {{-- Hero (redesign-spec.md Section 10.1 item 2): centered single
         column, eyebrow pill, display H1, lede, one primary and one
         secondary action, a check-icon microcopy row, then the
         product visual. Release 08b touches this block once, whole:
         D-1 restores the reading order on mobile (headline, lede,
         actions, trust row; the release 07 order overrides are
         gone from app.css); F1 gives mobile a genuine portrait
         dashboard capture in a phone-form frame (below); F2/F3 are
         handled in the consent card and the sticky bar script.
         Desktop keeps the window treatment and is otherwise
         unchanged.
         Copy release (2026-10-24, Amendment 11): the hero is
         Version 1 exactly as rendered in the owner-approved
         mockup (hidden_files/redesign/hero-mockups/v1.png): the
         H1 gains its terminal period, the lede states the
         mechanism in three short sentences, and the trust row
         leads with the free visits, then the per-visit price as
         fragments. Versions 2 to 4 stay parked as A/B candidates
         (copy-inventory Amendment 11).
         Headline change (Amendment 14, 2026-10-24): by owner
         order the H1 (and the title section above, HOME-CHROME-01)
         is now "Get Your Customers to Refer their Friends",
         owner-supplied verbatim with no terminal period, in the
         spirit of the v1 landing page title. The Amendment 11
         lede and trust row are unchanged. --}}
    <section class="bg-surface-0">
        <div class="shell mkt-hero-shell pt-6 lg:pt-20">
            <div class="mkt-hero mx-auto max-w-[880px] text-center">
                <p class="hidden lg:block"><span class="eyebrow-pill">Referral marketing for local businesses</span></p>
                <h1 class="mkt-hero-title type-display mt-6 text-text-1">Get Your Customers to Refer their Friends</h1>
                <p class="mkt-hero-lede type-lede mx-auto mt-6 max-w-[56ch] text-text-2">Customers scan your QR code. They send it to friends. You pay only when a friend walks in.</p>
                <div class="mkt-hero-actions mt-8 flex flex-wrap items-center justify-center gap-3">
                    <a class="btn btn-primary btn-lg" href="{{ route('register') }}">Start free</a>
                    <a class="btn btn-secondary btn-lg" href="#how-it-works">See how it works</a>
                </div>
                <ul class="mkt-hero-trust mt-6 flex flex-wrap items-center justify-center gap-x-5 gap-y-2">
                    <li class="type-caption flex items-center gap-2 text-text-2"><x-icon name="check" :size="16" class="text-success-strong" />First {{ (int) config('billing.free_visits_at_signup') }} visits free</li>
                    <li class="type-caption flex items-center gap-2 text-text-2"><x-icon name="check" :size="16" class="text-success-strong" />Then {{ \App\Support\Money::usd($plan->per_referral_cents) }} a visit</li>
                    <li class="type-caption flex items-center gap-2 text-text-2"><x-icon name="check" :size="16" class="text-success-strong" />No setup fee</li>
                    <li class="type-caption flex items-center gap-2 text-text-2"><x-icon name="check" :size="16" class="text-success-strong" />Cancel anytime</li>
                </ul>
            </div>

            {{-- Hero animation (owner spec, 2026-10-24; copy
                 inventory Amendment 13): the static dashboard
                 captures are replaced by a compact, native, coded
                 animation of the referral loop in one composed
                 scene: a customer scans the counter card's QR
                 code, the phone moves to the share screen (the
                 real flow's layout language: greeting, reward
                 line, the ink Share button, the link field), the
                 link travels to two friends, and the friends
                 arrive at the storefront; the owner's closing
                 line fades in, then the scene settles back into
                 its opening composition and loops. Pure CSS/SVG,
                 no video, no library. The opening frame carries
                 the whole story at a glance (card, phone
                 mid-scan, friends, storefront). Under
                 prefers-reduced-motion the animation is off and
                 the same scene sits in its composed static state
                 (share screen up, bubbles on their paths, friends
                 at the door, badge shown, closing line visible).
                 The old hero images stay in the tree untouched
                 (owner instruction) and are archived under
                 hidden_files/redesign/hero-assets-archive/. --}}
            <div class="mkt-hero-stage mx-auto max-w-[920px]">
                <div class="hero-stage" id="hero-stage" role="img" aria-label="How Easy Refer works: a customer scans the QR code on the counter card, gets a personal referral link on their phone, and shares it with friends, who then visit the business.">
                    <svg class="hero-scene" viewBox="0 0 880 560" fill="none" aria-hidden="true" focusable="false">
                        <defs>
                            <g id="hs-qr">
                                <rect x="0" y="0" width="18" height="18" fill="var(--color-text-1)"/><rect x="4" y="4" width="10" height="10" fill="var(--color-surface-0)"/><rect x="7" y="7" width="4" height="4" fill="var(--color-text-1)"/>
                                <rect x="76" y="0" width="18" height="18" fill="var(--color-text-1)"/><rect x="80" y="4" width="10" height="10" fill="var(--color-surface-0)"/><rect x="83" y="7" width="4" height="4" fill="var(--color-text-1)"/>
                                <rect x="0" y="76" width="18" height="18" fill="var(--color-text-1)"/><rect x="4" y="80" width="10" height="10" fill="var(--color-surface-0)"/><rect x="7" y="83" width="4" height="4" fill="var(--color-text-1)"/>
                                <path fill="var(--color-text-1)" d="M26 2h8v8h-8zM44 0h8v8h-8zM62 4h8v8h-8zM28 16h8v8h-8zM52 18h8v8h-8zM4 28h8v8H4zM22 30h8v8h-8zM40 26h8v8h-8zM66 28h8v8h-8zM84 24h8v8h-8zM12 44h8v8h-8zM32 46h8v8h-8zM56 42h8v8h-8zM76 46h8v8h-8zM2 58h8v8H2zM24 60h8v8h-8zM46 56h8v8h-8zM64 62h8v8h-8zM86 58h8v8h-8zM28 78h8v8h-8zM48 80h8v8h-8zM70 76h8v8h-8zM86 82h8v8h-8zM56 88h8v6h-8z"/>
                            </g>
                            <g id="hs-person">
                                <circle cx="0" cy="0" r="13" fill="var(--color-surface-0)" stroke="var(--color-line-strong)" stroke-width="2"/>
                                <path d="M-16 42 L-16 20 Q-16 7 0 7 Q16 7 16 20 L16 42 Z" fill="var(--color-surface-3)" stroke="var(--color-line-strong)" stroke-width="2"/>
                            </g>
                            <g id="hs-bubble">
                                <rect x="-21" y="-15" width="42" height="30" rx="10" fill="var(--color-surface-0)" stroke="var(--color-line-strong)" stroke-width="2"/>
                                <path d="M-6 15 L-6 24 L6 15 Z" fill="var(--color-surface-0)" stroke="var(--color-line-strong)" stroke-width="2" stroke-linejoin="round"/>
                                <path d="M-9 0 h7 M5 0 h7" stroke="var(--color-brand-500)" stroke-width="3.5" stroke-linecap="round"/>
                            </g>
                        </defs>

                        {{-- Backdrop and ground --}}
                        <circle cx="440" cy="250" r="235" fill="var(--color-surface-2)" opacity="0.55"/>
                        <rect x="0" y="438" width="880" height="122" fill="var(--color-surface-2)"/>

                        {{-- Storefront (right, back) --}}
                        <g>
                            <rect x="588" y="168" width="268" height="272" rx="10" fill="var(--color-surface-0)" stroke="var(--color-line)" stroke-width="2"/>
                            <rect x="576" y="146" width="292" height="24" rx="8" fill="var(--color-surface-3)"/>
                            <rect class="hs-glow hs-anim" x="600" y="278" width="244" height="162" rx="12" fill="var(--color-brand-500)"/>
                            <path d="M600 236 h240 l-14 26 h-212 Z" fill="var(--color-brand-500)"/>
                            <path d="M614 262 h212" stroke="var(--color-line-strong)" stroke-width="2" opacity="0.45"/>
                            <rect x="612" y="288" width="112" height="104" rx="6" fill="var(--color-surface-2)" stroke="var(--color-line)" stroke-width="2"/>
                            <path d="M668 288 v104 M612 340 h112" stroke="var(--color-line)" stroke-width="2"/>
                            <rect x="748" y="288" width="70" height="152" rx="6" fill="var(--color-surface-1)" stroke="var(--color-line-strong)" stroke-width="2"/>
                            <circle cx="806" cy="368" r="3.5" fill="var(--color-line-strong)"/>
                            <circle cx="650" cy="205" r="15" fill="var(--color-brand-500)"/>
                            <path d="M643 205 l5 5 l9 -10" stroke="var(--color-ink-950)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        </g>

                        {{-- Customer, standing behind the counter --}}
                        <g transform="translate(368 312)">
                            <use href="#hs-person" transform="scale(2.3)"/>
                        </g>

                        {{-- Counter (left, front) --}}
                        <rect x="34" y="402" width="440" height="14" rx="4" fill="var(--color-surface-3)"/>
                        <rect x="48" y="416" width="412" height="66" rx="6" fill="var(--color-surface-0)" stroke="var(--color-line)" stroke-width="2"/>
                        <g transform="translate(254 449)">
                            <rect x="-64" y="-14" width="128" height="28" rx="14" fill="var(--color-surface-1)" stroke="var(--color-line)" stroke-width="2"/>
                            <circle cx="-46" cy="0" r="8" fill="var(--color-brand-500)"/>
                            <path d="M-49.5 0 l2.5 2.5 l4.5 -5" stroke="var(--color-ink-950)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <rect x="-32" y="-3.5" width="52" height="7" rx="3.5" fill="var(--color-surface-3)"/>
                        </g>

                        {{-- The counter card with its QR code --}}
                        <g>
                            <rect x="102" y="212" width="146" height="192" rx="10" fill="var(--color-surface-0)" stroke="var(--color-line-strong)" stroke-width="2"/>
                            <rect x="118" y="228" width="20" height="20" rx="5" fill="var(--color-brand-500)"/>
                            <path d="M124 238 l4.5 4.5 l8 -9" stroke="var(--color-ink-950)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <rect x="146" y="231" width="62" height="6" rx="3" fill="var(--color-surface-3)"/>
                            <rect x="146" y="241" width="44" height="6" rx="3" fill="var(--color-surface-3)"/>
                            <use href="#hs-qr" transform="translate(115 262) scale(1.28)"/>
                            <rect x="122" y="382" width="106" height="6" rx="3" fill="var(--color-surface-3)"/>
                            <g class="hs-corners hs-anim" stroke="var(--color-action)" stroke-width="4" stroke-linecap="round">
                                <path d="M96 268 v-14 a6 6 0 0 1 6 -6 h14"/>
                                <path d="M240 248 h14 a6 6 0 0 1 6 6 v14"/>
                                <path d="M260 354 v14 a6 6 0 0 1 -6 6 h-14"/>
                                <path d="M110 374 h-14 a6 6 0 0 1 -6 -6 v-14"/>
                            </g>
                            <g class="hs-scanline hs-anim">
                                <rect x="100" y="254" width="150" height="30" rx="8" fill="var(--color-brand-500)" opacity="0.25"/>
                                <rect x="100" y="266" width="150" height="4" fill="var(--color-brand-500)"/>
                            </g>
                            <g class="hs-chip hs-anim">
                                <circle cx="252" cy="222" r="14" fill="var(--color-brand-500)"/>
                                <path d="M245 222 l5 5 l9 -10" stroke="var(--color-ink-950)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            </g>
                        </g>

                        {{-- The customer's phone, aimed at the card --}}
                        <g transform="translate(-8 2) rotate(-6 316 306)">
                            <rect x="284" y="242" width="66" height="128" rx="12" fill="var(--color-ink-900)" stroke="var(--color-line-strong)" stroke-width="2"/>
                            <rect x="289" y="249" width="56" height="114" rx="7" fill="var(--color-surface-0)"/>
                            {{-- Scan screen: viewfinder over the code --}}
                            <g class="hs-screen-scan hs-anim">
                                <path d="M298 276 v-8 a4 4 0 0 1 4 -4 h8 M324 264 h8 a4 4 0 0 1 4 4 v8 M336 324 v8 a4 4 0 0 1 -4 4 h-8 M310 336 h-8 a4 4 0 0 1 -4 -4 v-8" stroke="var(--color-text-3)" stroke-width="2.5" stroke-linecap="round"/>
                                <use href="#hs-qr" transform="translate(303 282) scale(0.30)"/>
                                <rect x="299" y="344" width="36" height="5" rx="2.5" fill="var(--color-surface-3)"/>
                            </g>
                            {{-- Share screen: the real flow's layout
                                 (greeting, reward line, the ink
                                 share button, the link field) --}}
                            <g class="hs-screen-share hs-anim">
                                <rect x="297" y="259" width="26" height="5" rx="2.5" fill="var(--color-surface-3)"/>
                                <rect x="297" y="272" width="40" height="7" rx="3.5" fill="var(--color-text-3)" opacity="0.55"/>
                                <rect x="297" y="285" width="34" height="5" rx="2.5" fill="var(--color-surface-3)"/>
                                <rect x="297" y="296" width="40" height="5" rx="2.5" fill="var(--color-surface-3)"/>
                                <rect x="295" y="308" width="44" height="17" rx="8.5" fill="var(--color-action)"/>
                                <path d="M306 316.5 h14 M316 311 l6 5.5 l-6 5.5" stroke="var(--color-on-action)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                                <rect x="295" y="331" width="30" height="13" rx="4" fill="var(--color-surface-1)" stroke="var(--color-line-strong)" stroke-width="1.5"/>
                                <rect x="325" y="331" width="14" height="13" rx="4" fill="var(--color-action)"/>
                                <rect x="299" y="351" width="36" height="5" rx="2.5" fill="var(--color-surface-3)"/>
                            </g>
                        </g>

                        {{-- The link travelling to the friends --}}
                        <path d="M352 258 C 410 240, 452 262, 492 296" stroke="var(--color-line-strong)" stroke-width="2" stroke-dasharray="1 7" stroke-linecap="round" opacity="0.6"/>
                        <path d="M360 302 C 432 292, 502 300, 554 312" stroke="var(--color-line-strong)" stroke-width="2" stroke-dasharray="1 7" stroke-linecap="round" opacity="0.6"/>
                        <use href="#hs-bubble" class="hs-bubble-1 hs-anim"/>
                        <use href="#hs-bubble" class="hs-bubble-2 hs-anim"/>

                        {{-- Friends: the outer group sits at the
                             storefront door (arrived); the animated
                             inner group's base transform holds each
                             friend at their waiting spot --}}
                        <g transform="translate(716 356) scale(1.9)">
                            <g class="hs-friend-1 hs-anim">
                                <use href="#hs-person"/>
                                <rect x="9" y="12" width="9" height="15" rx="2.5" fill="var(--color-ink-900)"/>
                                <circle class="hs-dot-1 hs-anim" cx="13.5" cy="17" r="2.2" fill="var(--color-brand-500)"/>
                            </g>
                        </g>
                        <g transform="translate(664 368) scale(1.75)">
                            <g class="hs-friend-2 hs-anim">
                                <use href="#hs-person"/>
                                <rect x="9" y="12" width="9" height="15" rx="2.5" fill="var(--color-ink-900)"/>
                                <circle class="hs-dot-2 hs-anim" cx="13.5" cy="17" r="2.2" fill="var(--color-brand-500)"/>
                            </g>
                        </g>

                        {{-- Success badge at the door --}}
                        <g class="hs-badge hs-anim">
                            <circle cx="838" cy="296" r="17" fill="var(--color-brand-500)"/>
                            <path d="M830 296 l6 6 l11 -12" stroke="var(--color-ink-950)" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </g>
                    </svg>
                    <p class="hero-stage-line hs-anim" aria-hidden="true">Turn every customer into a referral.</p>
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
    {{-- 08f (contrast audit F-10): section grounds are assigned
         to alternate down the page, so no two neighbours share a
         ground. This band takes the cream ground and its stat
         panel inverts to white, keeping the panel a designed
         object on the new ground. --}}
    <section class="border-y border-line bg-surface-1">
        <div class="shell py-14 lg:py-20">
            <p class="type-eyebrow text-center text-link">What stores see with Easy Refer</p>
            <div class="mx-auto mt-6 max-w-[1080px] rounded-2xl border border-line bg-surface-0 px-4 py-8 sm:px-8">
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
                        <p class="type-stat text-text-1">{{ config('claims.first_visits_free') }}</p>
                        <p class="type-caption mt-2 text-text-2">visits free</p>
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
    {{-- Success Stories (HOME-SUC-01, HOME-SUC-02): the OD-6
         approved set, verbatim. No photo attaches to either card:
         no owner-supplied photo depicts either business (AP-07).
         Redesign: quote panels at radius-xl with an initials
         medallion and the result figure in brand-700 title-3 with
         its caption under it (never styled as a link).
         Copy release (2026-10-24, Amendment 11): the section now
         sits BEFORE How It Works (tactics A-02: the payoff before
         the homework); its white ground is unchanged and the
         sections below re-take their alternating grounds. --}}
    <section id="success-stories" class="bg-surface-0">
        <div class="shell section-pad">
            <h2 class="type-title-1 text-text-1">Success stories</h2>
            <div class="mt-12 grid gap-6 md:grid-cols-2">
                <figure class="rounded-xl border border-line bg-surface-0 shadow-e1" style="padding: 32px">
                    <blockquote class="type-quote text-text-1">We used to spend a lot of money on Google Ads. Now our customers do the marketing for us.</blockquote>
                    <figcaption class="mt-6 flex items-start justify-between gap-4">
                        <span class="flex items-center gap-3">
                            <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-brand-50 text-text-1" aria-hidden="true"><span class="type-caption" style="font-weight: 650">NR</span></span>
                            <span><span class="type-title-4 block text-text-1">Nolan and Megan Ralston, Owners, Pizzeria</span></span>
                        </span>
                        <span class="type-title-3 max-w-[220px] text-right text-text-1">150+ New Customers In 3 months</span>
                    </figcaption>
                </figure>
                <figure class="rounded-xl border border-line bg-surface-0 shadow-e1" style="padding: 32px">
                    <blockquote class="type-quote text-text-1">I would have used Easy Refer since day 1 if it was available.</blockquote>
                    <figcaption class="mt-6 flex items-start justify-between gap-4">
                        <span class="flex items-center gap-3">
                            <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-brand-50 text-text-1" aria-hidden="true"><span class="type-caption" style="font-weight: 650">TH</span></span>
                            <span><span class="type-title-4 block text-text-1">Talia Hollis, Hairstylist</span></span>
                        </span>
                        <span class="type-title-3 max-w-[220px] text-right text-text-1">110+ New Customers In 4 months</span>
                    </figcaption>
                </figure>
            </div>
            <p class="mt-10 text-center"><a class="btn btn-primary btn-lg" href="{{ route('register') }}">Start free</a></p>
        </div>
    </section>


    {{-- 08f (contrast audit F-10): section grounds alternate
         down the page; this section takes the cream ground so
         the alternation holds (STEP-CONTRAST guards the pairs).
         HIW-C (owner decision 2026-10-24): the steps grid is
         replaced by the Variant C scroll story (four viewport
         beats plus a progress strip, in the story partial);
         the shared steps partial still serves the vertical
         pages untouched. The poster figure moves below the
         beats, centered at the variant's measure, and carries
         the AI cafe scene (W-3); its window chrome and bar
         label are unchanged. --}}
    <section id="how-it-works" class="bg-surface-1">
        <div class="shell section-pad">
            {{-- D-3 (release 08b): the eyebrow repeated this H2
                 verbatim; it is gone, here and on the features,
                 stories, and pricing sections below. --}}
            <h2 class="type-title-1 text-text-1">How it works</h2>
            @include('partials.how-it-works-story')
            <figure class="mx-auto mt-16 max-w-[560px]">
                <div class="window">
                    <div class="window-bar" aria-hidden="true">
                        <span class="window-dot"></span><span class="window-dot"></span><span class="window-dot"></span>
                        <span class="window-url">Your printed poster</span>
                        <span class="w-10"></span>
                    </div>
                    <picture>
                        <source type="image/webp" srcset="{{ asset('images/poster-ai-a-scene2-480.webp') }} 480w, {{ asset('images/poster-ai-a-scene2-900.webp') }} 900w" sizes="(max-width: 639px) calc(100vw - 2.5rem), 560px">
                        <source type="image/jpeg" srcset="{{ asset('images/poster-ai-a-scene2-480.jpg') }} 480w, {{ asset('images/poster-ai-a-scene2-900.jpg') }} 900w" sizes="(max-width: 639px) calc(100vw - 2.5rem), 560px">
                        <img src="{{ asset('images/poster-ai-a-scene2-900.jpg') }}" width="900" height="900" alt="A printed counter poster in a stand on a café counter, reading 'Refer a Friend to Win a Free Coffee!' above a large QR code customers scan to join and start referring." loading="lazy" decoding="async" class="block w-full">
                    </picture>
                </div>
            </figure>
            <p class="mt-10 text-center"><a class="btn btn-primary btn-lg" href="{{ route('register') }}">Start free</a></p>
        </div>
    </section>

    {{-- Proof, part 2 (release 08b, D-2/DG-1): the real-store
         photos as their own section after the mechanism, each
         photo paired with its own business (AP-07), mobile crops
         held to about 60% of a viewport. --}}
    <section class="bg-surface-0">
        {{-- R-3 (release 08d): compact bottom step on mobile.
             08f (contrast audit F-10): section grounds alternate.
             Copy release (2026-10-24): with Stories ahead of How
             It Works, this section takes the white ground so the
             alternation holds; lede and captions take the
             slim-down wording, and the eyebrow-to-H2 and
             H2-to-lede gaps take the PROSE-GAP floors (mt-4). --}}
        <div class="shell section-pad section-pad-compact-b">
            <p class="type-eyebrow text-link">In store now</p>
            <h2 class="type-title-1 mt-4 text-text-1">Running in real stores</h2>
            <p class="type-lede mt-4 max-w-[56ch] text-text-2">Real counters. Real customers. The same code your customers scan.</p>
            <div class="mt-12 grid gap-6 md:grid-cols-3">
                <figure>
                    <picture>
                        <source type="image/webp" srcset="{{ asset('images/photos/ungaro-counter-480.webp') }} 480w, {{ asset('images/photos/ungaro-counter-768.webp') }} 768w, {{ asset('images/photos/ungaro-counter-1020.webp') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                        <source type="image/jpeg" srcset="{{ asset('images/photos/ungaro-counter-480.jpg') }} 480w, {{ asset('images/photos/ungaro-counter-768.jpg') }} 768w, {{ asset('images/photos/ungaro-counter-1020.jpg') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                        <img src="{{ asset('images/photos/ungaro-counter-768.jpg') }}" width="1020" height="1275" alt="A printed Easy Refer referral sign with a QR code on the counter at Ungaro Coal Fired Pizza Cafe." loading="lazy" decoding="async" class="aspect-[5/4] w-full rounded-xl object-cover md:aspect-[4/5]">
                    </picture>
                    <figcaption class="type-caption mt-4 text-text-2">Ungaro Coal Fired Pizza Cafe, with its code at the register.</figcaption>
                </figure>
                <figure>
                    <picture>
                        <source type="image/webp" srcset="{{ asset('images/photos/reception-desk-480.webp') }} 480w, {{ asset('images/photos/reception-desk-768.webp') }} 768w, {{ asset('images/photos/reception-desk-1020.webp') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                        <source type="image/jpeg" srcset="{{ asset('images/photos/reception-desk-480.jpg') }} 480w, {{ asset('images/photos/reception-desk-768.jpg') }} 768w, {{ asset('images/photos/reception-desk-1020.jpg') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                        <img src="{{ asset('images/photos/reception-desk-768.jpg') }}" width="1020" height="1230" alt="A printed Easy Refer referral sign with a QR code in an acrylic holder on the reception desk at Focus Vision clinic." loading="lazy" decoding="async" class="aspect-[5/4] w-full rounded-xl object-cover md:aspect-[4/5]">
                    </picture>
                    <figcaption class="type-caption mt-4 text-text-2">Focus Vision clinic, where patients join at check-in.</figcaption>
                </figure>
                <figure>
                    <picture>
                        <source type="image/webp" srcset="{{ asset('images/photos/pharmacy-counter-480.webp') }} 480w, {{ asset('images/photos/pharmacy-counter-768.webp') }} 768w, {{ asset('images/photos/pharmacy-counter-1020.webp') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                        <source type="image/jpeg" srcset="{{ asset('images/photos/pharmacy-counter-480.jpg') }} 480w, {{ asset('images/photos/pharmacy-counter-768.jpg') }} 768w, {{ asset('images/photos/pharmacy-counter-1020.jpg') }} 1020w" sizes="(min-width: 768px) 33vw, 100vw">
                        <img src="{{ asset('images/photos/pharmacy-counter-768.jpg') }}" width="1020" height="1275" alt="A printed Easy Refer referral poster with a QR code at a pharmacy counter in Europe." loading="lazy" decoding="async" class="aspect-[5/4] w-full rounded-xl object-cover md:aspect-[4/5]">
                    </picture>
                    <figcaption class="type-caption mt-4 text-text-2">A pharmacy counter in Europe. The same program, in Albanian.</figcaption>
                </figure>
            </div>
            <p class="mt-10 text-center"><a class="btn btn-primary btn-lg" href="{{ route('register') }}">Start free</a></p>
        </div>
    </section>

    {{-- Benefits render as text blocks without card chrome (AP-03);
         the approved stats live in the proof section above.
         Redesign: each column leads with an icon tile (Section
         10.1 item 5). --}}
    {{-- Copy release (2026-10-24): the section takes the cream
         ground in the post-reorder alternation; the three bodies
         take the slim-down wording (the pricing column stops
         restating the cap, which stands in the hero row, the
         pricing lede and card, and FAQ-03: the full offer is
         stated at most three times page-wide, hero, pricing,
         closing), and the gaps take the PROSE-GAP floors
         (eyebrow to H2 mt-4, H3 to body mt-3). --}}
    <section id="benefits" class="bg-surface-1">
        <div class="shell section-pad">
            <p class="type-eyebrow text-link">Benefits</p>
            <h2 class="type-title-1 mt-4 text-text-1">Why use Easy Refer?</h2>
            <div class="mt-12 grid gap-10 md:grid-cols-3">
                <div>
                    <p class="flex h-11 w-11 items-center justify-center rounded-lg bg-surface-2 text-text-1"><x-icon name="users" :size="24" /></p>
                    <h3 class="type-title-3 mt-4 text-text-1">Attract new customers</h3>
                    <p class="type-body mt-3 max-w-[68ch] text-text-2">Happy customers tell their friends. Easy Refer gives them a code to scan and a link to send.</p>
                </div>
                <div>
                    <p class="flex h-11 w-11 items-center justify-center rounded-lg bg-surface-2 text-text-1"><x-icon name="trending-up" :size="24" /></p>
                    <h3 class="type-title-3 mt-4 text-text-1">Increase revenue</h3>
                    <p class="type-body mt-3 max-w-[68ch] text-text-2">Friends come in for a reward. Your customers come back for theirs. Both sides win.</p>
                </div>
                <div>
                    <p class="flex h-11 w-11 items-center justify-center rounded-lg bg-surface-2 text-text-1"><x-icon name="wallet" :size="24" /></p>
                    <h3 class="type-title-3 mt-4 text-text-1">Affordable pricing</h3>
                    <p class="type-body mt-3 max-w-[68ch] text-text-2">You pay only when a referred friend walks in. No monthly fee, no setup fee.</p>
                </div>
            </div>
            {{-- S-04: on mobile this section ran about 1,250px with no
                 action and no proof, a dead stretch; it now closes
                 with the primary action like every other block. --}}
            <p class="mt-10 text-center"><a class="btn btn-primary btn-lg" href="{{ route('register') }}">Start free</a></p>
        </div>
    </section>

    {{-- Copy release (2026-10-24): the section takes the white
         ground in the post-reorder alternation and its panels
         invert to the cream ground; the panels also receive the
         eight How It Works micro-bullets demoted from the steps
         (progressive disclosure, tactics T-05): the scan and
         claim bullets (steps 1 and 3) join the QR panel's check
         rows, the share and engage bullets (steps 2 and 4) form
         the SMS panel's new check list. --}}
    <section id="features" class="bg-surface-0">
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
                <div class="min-w-0 rounded-xl border border-line bg-surface-1 shadow-e1" style="padding: 32px">
                    {{-- D-4 (release 08b): the panel title takes the
                         H3 step, the mechanism is one short line,
                         and the safeguards read as a check row, so
                         the panel no longer leads with a wall. Copy
                         is R5 with S-10's opening.
                         Copy release (2026-10-24): the body takes
                         the slim-down wording (the dashboard-worth
                         clause is cut; the calculator makes that
                         point with the visitor's own numbers), one
                         check row takes the simpler verb, and the
                         demoted step 1 and 3 bullets join the list. --}}
                    <h3 class="type-title-3 text-text-1">QR Code referrals</h3>
                    <p class="type-body mt-3 text-text-2">Print your code. Put it by the register. Customers join in seconds.</p>
                    <ul class="mt-3 space-y-2">
                        <li class="type-body-sm flex items-start gap-2 text-text-2"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-success-strong" />Instant QR code generation</li>
                        <li class="type-body-sm flex items-start gap-2 text-text-2"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-success-strong" />Multiple placement options</li>
                        <li class="type-body-sm flex items-start gap-2 text-text-2"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-success-strong" />Every visit is checked before it counts</li>
                        <li class="type-body-sm flex items-start gap-2 text-text-2"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-success-strong" />Instant validation</li>
                        <li class="type-body-sm flex items-start gap-2 text-text-2"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-success-strong" />Flexible reward options</li>
                        <li class="type-body-sm flex items-start gap-2 text-text-2"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-success-strong" />Rewards are checked before they pay out</li>
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
                <div class="min-w-0 rounded-xl border border-line bg-surface-1 shadow-e1" style="padding: 32px">
                    <h3 class="type-title-3 text-text-1">SMS marketing</h3>
                    {{-- Copy release (2026-10-24): slim-down body
                         (the STOP opt-out fact is legally
                         load-bearing and stays), and the demoted
                         step 2 and 4 bullets form the check list,
                         mirroring the QR panel. --}}
                    <p class="type-body mt-3 text-text-2">Text your customers in one go. Anyone who replies STOP is out, automatically.</p>
                    <ul class="mt-3 space-y-2">
                        <li class="type-body-sm flex items-start gap-2 text-text-2"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-success-strong" />Native share sheet and copy link</li>
                        <li class="type-body-sm flex items-start gap-2 text-text-2"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-success-strong" />Customizable messages</li>
                        <li class="type-body-sm flex items-start gap-2 text-text-2"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-success-strong" />Real-time analytics</li>
                        <li class="type-body-sm flex items-start gap-2 text-text-2"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-success-strong" />Bulk SMS campaigns</li>
                    </ul>
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

    {{-- Release 08b (item 2.2, DG-5): pricing and the calculator
         are sibling sections, not one stack inside #pricing. The
         pricing card keeps its accepted desktop co-visibility
         with the calculator heading below it. The sticky bar's
         #pricing suppression zone is unchanged. (The third 08b
         sibling, the home FAQ section, is removed by the copy
         release, O-1.) --}}
    <section id="pricing" class="bg-surface-1">
        <div class="shell section-pad">
            <h2 class="type-title-1 text-center text-text-1">Simple, transparent pricing</h2>
            {{-- Copy release (Amendment 11): slim-down lede;
                 PROSE-GAP floor lifts it to mt-4. --}}
            <p class="type-lede mx-auto mt-4 max-w-[56ch] text-center text-text-2">One plan. {{ \App\Support\Money::usd($plan->per_referral_cents) }} when a referred friend visits. Never more than {{ \App\Support\Money::usd($plan->monthly_cap_cents) }} a month.</p>
            <div class="mt-12">
                @include('partials.pricing-plan')
            </div>
            {{-- Amendment 12 (release 11, HOME-PRC-05): the plain
                 definition of a visit that counts, met at the
                 price. Same words close the FAQ-03 answer. --}}
            <p class="type-body mx-auto mt-6 max-w-[56ch] text-center text-text-2">A visit counts when a referred friend comes in and uses their reward. We confirm their phone first, and every visit is checked before it counts.</p>
            {{-- Amendment 12 (release 11, HOME-PRC-06): the bulk
                 text rate stated in public, next to the processor
                 trust line. The rate and the example total render
                 from config (100 customers, one segment each). --}}
            <p class="type-body mx-auto mt-3 max-w-[56ch] text-center text-text-2">Bulk texts cost {{ (int) config('billing.sms_segment_price_cents') }} cents per segment for each customer. Most short texts are one segment. One text to 100 customers costs {{ \App\Support\Money::usd(100 * (int) config('billing.sms_segment_price_cents')) }}.</p>
            <p class="type-caption mt-4 text-center text-text-2">Square handles payments. Twilio handles text messages.</p>
        </div>
    </section>

    {{-- 08f (contrast audit F-10): the calculator block takes
         the white ground so it stops running on from the cream
         pricing section above it; its worksheet card now reads
         through the strengthened line and e1 shadow. --}}
    <section class="bg-surface-0">
        <div class="shell pb-24">
            @include('partials.calculator')
        </div>
    </section>

    {{-- Copy release (2026-10-24, O-1): the home FAQ subset is
         removed. The full set lives on /faq; the verticals keep
         their embedded subset. S-01's older disposition (the
         starter kit standing before the closing band) is also
         superseded here: the kit merges INTO the closing band
         (O-3, below), so the page has one ending. --}}

    {{-- Closing CTA (HOME-CTA-01 with the OD-6 approved body line,
         option (b); HOME-CTA-02 bullets). The band's button is the
         btn-accent component (CF-07). Redesign (Section 10.1 item
         9): centered column on ink-950, reversed lockup, bullets as
         one centered check row with accent checks.
         Copy release (2026-10-24, Amendment 11): the band gains
         its adjacent proof element (tactics T-06): the OD-6
         Ralston result figure with its attribution (HOME-CTA-03),
         and the starter kit renders inside the band under the
         divider (O-3), one ask cluster instead of two adjacent
         asks. --}}
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
                <p class="type-caption mt-6 text-dark-muted"><span class="type-title-4 text-white">150+ New Customers In 3 months</span> — Nolan and Megan Ralston, Owners, Pizzeria</p>
            </div>
            @include('partials.starter-kit', ['source' => 'home', 'band' => true])
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
            // Hero animation: pause the loop while the stage is
            // off screen or the tab is hidden (owner spec,
            // 2026-10-24). Purely presentational; the timeline
            // itself is CSS.
            var stage = document.getElementById('hero-stage');
            if (!stage || !('IntersectionObserver' in window)) {
                return;
            }
            var inView = true;
            var apply = function () {
                stage.classList.toggle('hs-paused', !inView || document.hidden);
            };
            new IntersectionObserver(function (entries) {
                inView = entries[0].isIntersecting;
                apply();
            }, { threshold: 0.05 }).observe(stage);
            document.addEventListener('visibilitychange', apply);
        })();
    </script>
    <script>
        (function () {
            // How-it-works story: scroll reveal + progress for the
            // four beats (partials/how-it-works-story). Enhancement
            // only. The beats render visible by default; the
            // .hiw-live gate class that hides them pre-reveal is
            // added solely on this path (observer available,
            // reduced motion off). Reduced motion or no observer:
            // every beat is marked in and the strip stands
            // complete, matching the static no-JS rendering.
            var story = document.getElementById('hiw-story');
            if (!story) {
                return;
            }
            var beats = story.querySelectorAll('.hiw-beat');
            var fill = document.getElementById('hiw-pfill');
            var labels = story.querySelectorAll('[data-hiw-plabel]');
            function setProgress(n) {
                if (fill) {
                    fill.style.width = (n * 25) + '%';
                }
                for (var i = 0; i < labels.length; i++) {
                    labels[i].classList.toggle('on', i < n);
                }
            }
            var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (reduced || !('IntersectionObserver' in window)) {
                for (var i = 0; i < beats.length; i++) {
                    beats[i].classList.add('hiw-in');
                }
                setProgress(4);
                return;
            }
            story.classList.add('hiw-live');
            setProgress(0);
            // Reveal observer: one-shot, unchanged. A beat is
            // marked in the first time it is meaningfully in view
            // and stays revealed.
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) {
                        return;
                    }
                    entry.target.classList.add('hiw-in');
                    io.unobserve(entry.target);
                });
            }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
            for (var j = 0; j < beats.length; j++) {
                io.observe(beats[j]);
            }
            // Progress observer: the strip tracks the CURRENT beat
            // in both directions. A thin band at the viewport
            // center is watched for every beat and never
            // unobserved; whichever beat crosses the band sets the
            // progress, so scrolling back up walks the strip back
            // down (owner catch 2026-10-24: the old seen-count
            // only ever grew, leaving the strip full at beat 1).
            var pio = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) {
                        return;
                    }
                    setProgress(parseInt(entry.target.getAttribute('data-beat'), 10));
                });
            }, { rootMargin: '-45% 0px -45% 0px', threshold: 0 });
            for (var k = 0; k < beats.length; k++) {
                pio.observe(beats[k]);
            }
        })();
    </script>
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
