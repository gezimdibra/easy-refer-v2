{{--
    The home page's how-it-works story (owner decision 2026-10-24:
    Variant C's step-by-step scroll story substitutes the steps grid
    in marketing/home). Four viewport beats with hand-drawn scenes
    and a progress strip pinned at the section top. Copy discipline:
    the beat lines are the shipped step/pricing lines (the beat-4
    line and extras render with the same expressions as the pricing
    section, so they cannot drift); the beat headlines and the strip
    labels are the variant's own, adopted with it. The shared
    partials/how-it-works partial still serves the vertical pages
    untouched.

    Behavior contract: every beat is VISIBLE BY DEFAULT. The reveal
    is an enhancement the inline script in marketing/home layers on
    only when it can run the observer (IntersectionObserver present,
    reduced motion off) by adding .hiw-live to #hiw-story; the
    hiding rules in app.css exist only under that class. Without JS
    or under reduced motion the section renders statically in order
    and the strip stands as a static 4-step indicator (markup ships
    the completed state; the script resets it when it takes over).
--}}
<div class="hiw-story mt-12" id="hiw-story">
    <div class="hiw-progress" aria-hidden="true">
        <div class="hiw-plabels">
            <span class="hiw-plabel on" data-hiw-plabel="1">1 · Scan</span>
            <span class="hiw-plabel on" data-hiw-plabel="2">2 · Share</span>
            <span class="hiw-plabel on" data-hiw-plabel="3">3 · Friend visits</span>
            <span class="hiw-plabel on" data-hiw-plabel="4">4 · You pay per visit</span>
        </div>
        <div class="hiw-track"><div class="hiw-fill" id="hiw-pfill" style="width: 100%"></div></div>
    </div>

    <section class="hiw-beat" data-beat="1">
        <div class="hiw-beat-grid">
            <div class="hiw-anim">
                <div class="hiw-num">1</div>
                <h3 class="type-title-1 mt-4 text-text-1">A customer scans your code</h3>
                <p class="type-lede mt-3 max-w-[420px] text-text-2">Customers scan your unique QR code at checkout.</p>
            </div>
            <div class="hiw-scene hiw-anim hiw-d1" role="img" aria-label="A customer at a shop counter holding a phone up to scan the QR code on a printed poster, with a scan line sweeping across the code.">
                <svg viewBox="0 0 520 340" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                    <rect x="10" y="238" width="500" height="16" rx="6" fill="var(--color-surface-3)" />
                    <rect x="10" y="254" width="500" height="56" fill="var(--color-line-strong)" />
                    <rect x="330" y="52" width="130" height="186" rx="8" fill="var(--color-surface-0)" stroke="var(--color-line-strong)" stroke-width="2" />
                    <text x="395" y="82" text-anchor="middle" font-weight="700" font-size="15" fill="var(--color-text-1)">Refer a Friend</text>
                    <g fill="var(--color-ink-900)">
                        <rect x="358" y="96" width="74" height="74" />
                    </g>
                    <g fill="var(--color-white)">
                        <rect x="366" y="104" width="16" height="16" /><rect x="408" y="104" width="16" height="16" /><rect x="366" y="146" width="16" height="16" />
                        <rect x="390" y="112" width="10" height="10" /><rect x="396" y="134" width="12" height="12" /><rect x="410" y="150" width="12" height="12" /><rect x="372" y="128" width="10" height="10" />
                    </g>
                    <rect class="hiw-scanline" x="352" y="94" width="86" height="5" rx="2.5" fill="var(--color-brand-500)" />
                    <text x="395" y="196" text-anchor="middle" font-size="12" fill="var(--color-text-2)">Scan to join</text>
                    <rect x="388" y="206" width="14" height="32" fill="var(--color-line-strong)" />
                    <rect x="66" y="86" width="104" height="196" rx="18" fill="var(--color-ink-900)" />
                    <rect x="76" y="104" width="84" height="140" rx="8" fill="var(--color-surface-1)" />
                    <rect x="96" y="126" width="44" height="44" fill="none" stroke="var(--color-text-1)" stroke-width="5" />
                    <rect x="112" y="142" width="12" height="12" fill="var(--color-text-1)" />
                    <rect x="90" y="196" width="56" height="8" rx="4" fill="var(--color-line-strong)" />
                    <rect x="90" y="214" width="40" height="8" rx="4" fill="var(--color-surface-3)" />
                    <circle cx="118" cy="262" r="7" fill="var(--color-line-strong)" />
                    <path d="M170 190 L250 150 L330 140" stroke="var(--color-brand-500)" stroke-width="3" stroke-dasharray="2 9" stroke-linecap="round" fill="none" />
                    <circle cx="252" cy="300" r="26" fill="var(--color-brand-500)" />
                    <text x="252" y="307" text-anchor="middle" font-size="13" font-weight="700" fill="var(--color-ink-950)">SCAN</text>
                </svg>
            </div>
        </div>
    </section>

    <section class="hiw-beat" data-beat="2">
        <div class="hiw-beat-grid">
            <div class="hiw-anim lg:order-2">
                <div class="hiw-num">2</div>
                <h3 class="type-title-1 mt-4 text-text-1">They share it with a friend</h3>
                <p class="type-lede mt-3 max-w-[420px] text-text-2">They send their link to friends.</p>
            </div>
            <div class="hiw-scene hiw-anim hiw-d1 lg:order-1" role="img" aria-label="A phone sending a referral link along a dotted path to three friends, whose avatars light up one by one.">
                <svg viewBox="0 0 520 340" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                    <rect x="52" y="60" width="118" height="224" rx="20" fill="var(--color-ink-900)" />
                    <rect x="63" y="80" width="96" height="160" rx="8" fill="var(--color-surface-1)" />
                    <rect x="76" y="98" width="70" height="34" rx="8" fill="var(--color-brand-500)" />
                    <text x="111" y="119" text-anchor="middle" font-size="12" font-weight="700" fill="var(--color-ink-950)">Your link</text>
                    <rect x="76" y="146" width="70" height="10" rx="5" fill="var(--color-line-strong)" />
                    <rect x="76" y="164" width="52" height="10" rx="5" fill="var(--color-surface-3)" />
                    <rect x="76" y="196" width="70" height="30" rx="15" fill="var(--color-ink-900)" />
                    <text x="111" y="215" text-anchor="middle" font-size="12" font-weight="700" fill="var(--color-brand-50)">Share →</text>
                    <circle cx="111" cy="264" r="6" fill="var(--color-line-strong)" />
                    <path class="hiw-share-path" d="M180 130 C 260 110, 300 84, 356 78" stroke="var(--color-brand-500)" stroke-width="3.5" fill="none" stroke-linecap="round" />
                    <path class="hiw-share-path" d="M180 176 C 260 176, 300 172, 356 172" stroke="var(--color-brand-500)" stroke-width="3.5" fill="none" stroke-linecap="round" />
                    <path class="hiw-share-path" d="M180 222 C 260 244, 300 262, 356 266" stroke="var(--color-brand-500)" stroke-width="3.5" fill="none" stroke-linecap="round" />
                    <g class="hiw-fdot hiw-fd1"><circle cx="404" cy="76" r="34" fill="var(--color-brand-500)" /><circle cx="404" cy="66" r="12" fill="var(--color-ink-950)" /><path d="M384 96 a20 14 0 0 1 40 0 z" fill="var(--color-ink-950)" /></g>
                    <g class="hiw-fdot hiw-fd2"><circle cx="404" cy="172" r="34" fill="var(--color-brand-500)" /><circle cx="404" cy="162" r="12" fill="var(--color-ink-950)" /><path d="M384 192 a20 14 0 0 1 40 0 z" fill="var(--color-ink-950)" /></g>
                    <g class="hiw-fdot hiw-fd3"><circle cx="404" cy="268" r="34" fill="var(--color-brand-500)" /><circle cx="404" cy="258" r="12" fill="var(--color-ink-950)" /><path d="M384 288 a20 14 0 0 1 40 0 z" fill="var(--color-ink-950)" /></g>
                    <text x="404" y="322" text-anchor="middle" font-size="12" fill="var(--color-text-2)">friends</text>
                </svg>
            </div>
        </div>
    </section>

    <section class="hiw-beat" data-beat="3">
        <div class="hiw-beat-grid">
            <div class="hiw-anim">
                <div class="hiw-num">3</div>
                <h3 class="type-title-1 mt-4 text-text-1">The friend walks in and claims their reward</h3>
                <p class="type-lede mt-3 max-w-[420px] text-text-2">Friends visit your store and receive their welcome reward.</p>
            </div>
            <div class="hiw-scene hiw-anim hiw-d1" role="img" aria-label="A friend arriving through a shop door, with a large check mark confirming their welcome reward.">
                <svg viewBox="0 0 520 340" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                    <rect x="150" y="36" width="220" height="268" rx="10" fill="var(--color-ink-900)" />
                    <rect x="168" y="54" width="84" height="250" fill="var(--color-surface-1)" />
                    <rect x="268" y="54" width="84" height="250" fill="var(--color-surface-2)" />
                    <circle cx="262" cy="186" r="6" fill="var(--color-brand-500)" />
                    <rect x="150" y="20" width="220" height="26" rx="8" fill="var(--color-brand-500)" />
                    <text x="260" y="38" text-anchor="middle" font-size="13" font-weight="700" fill="var(--color-ink-950)">OPEN</text>
                    <circle cx="212" cy="120" r="17" fill="var(--color-text-1)" />
                    <path d="M212 140 v52 M212 156 l-24 14 M212 156 l24 14 M212 192 l-18 46 M212 192 l20 46" stroke="var(--color-text-1)" stroke-width="9" stroke-linecap="round" fill="none" />
                    <rect x="66" y="238" width="388" height="10" rx="5" fill="var(--color-line-strong)" />
                    <g class="hiw-check-pop">
                        <circle cx="424" cy="96" r="40" fill="var(--color-success-strong)" />
                        <path d="M404 97 l14 14 l26 -30" stroke="var(--color-white)" stroke-width="9" fill="none" stroke-linecap="round" stroke-linejoin="round" />
                    </g>
                    <text x="424" y="158" text-anchor="middle" font-size="12" font-weight="700" fill="var(--color-text-2)">Reward claimed</text>
                </svg>
            </div>
        </div>
    </section>

    <section class="hiw-beat" data-beat="4">
        <div class="hiw-beat-grid">
            <div class="hiw-anim lg:order-2">
                <div class="hiw-num">4</div>
                <h3 class="type-title-1 mt-4 text-text-1">You pay only for visits</h3>
                <p class="type-lede mt-3 max-w-[420px] text-text-2">One plan. {{ \App\Support\Money::usd($plan->per_referral_cents) }} when a referred friend visits. Never more than {{ \App\Support\Money::usd($plan->monthly_cap_cents) }} a month.</p>
                <ul class="type-body mt-[18px] space-y-2">
                    <li class="flex items-start gap-2 text-text-2"><x-icon name="check" :size="20" class="mt-1 flex-none text-success-strong" />First {{ (int) config('billing.free_visits_at_signup') }} visits free</li>
                    <li class="flex items-start gap-2 text-text-2"><x-icon name="check" :size="20" class="mt-1 flex-none text-success-strong" />{{ \App\Support\Money::usd($plan->price_cents ?? 0) }}/month, {{ \App\Support\Money::usd($plan->per_referral_cents) }} per referral visit</li>
                    <li class="flex items-start gap-2 text-text-2"><x-icon name="check" :size="20" class="mt-1 flex-none text-success-strong" />No setup fee</li>
                    <li class="flex items-start gap-2 text-text-2"><x-icon name="check" :size="20" class="mt-1 flex-none text-success-strong" />Cancel anytime</li>
                </ul>
            </div>
            <div class="hiw-scene hiw-anim hiw-d1 lg:order-1" role="img" aria-label="A tally of referral visits, the first marked free and later visits marked $2, with the monthly charge capped at $99.">
                <svg viewBox="0 0 520 340" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                    <text x="36" y="52" font-weight="700" font-size="19" fill="var(--color-text-1)">Visits this month</text>
                    <g font-weight="700" font-size="14">
                        <g class="hiw-tally hiw-tc1"><circle cx="58" cy="96" r="17" fill="var(--color-success-fill)" /><text x="58" y="101" text-anchor="middle" fill="var(--color-success-strong)">✓</text><text x="90" y="101" fill="var(--color-text-2)">Visit 1</text><text x="440" y="101" text-anchor="end" fill="var(--color-success-strong)">FREE</text></g>
                        <g class="hiw-tally hiw-tc2"><circle cx="58" cy="140" r="17" fill="var(--color-success-fill)" /><text x="58" y="145" text-anchor="middle" fill="var(--color-success-strong)">✓</text><text x="90" y="145" fill="var(--color-text-2)">Visit 2</text><text x="440" y="145" text-anchor="end" fill="var(--color-success-strong)">FREE</text></g>
                        <g class="hiw-tally hiw-tc3"><circle cx="58" cy="184" r="17" fill="var(--color-brand-500)" /><text x="58" y="189" text-anchor="middle" fill="var(--color-ink-950)">✓</text><text x="90" y="189" fill="var(--color-text-2)">Visit 6</text><text x="440" y="189" text-anchor="end" fill="var(--color-text-1)">$2</text></g>
                        <g class="hiw-tally hiw-tc4"><circle cx="58" cy="228" r="17" fill="var(--color-brand-500)" /><text x="58" y="233" text-anchor="middle" fill="var(--color-ink-950)">✓</text><text x="90" y="233" fill="var(--color-text-2)">Visit 7</text><text x="440" y="233" text-anchor="end" fill="var(--color-text-1)">$2</text></g>
                        <g class="hiw-tally hiw-tc5"><circle cx="58" cy="272" r="17" fill="var(--color-brand-500)" /><text x="58" y="277" text-anchor="middle" fill="var(--color-ink-950)">✓</text><text x="90" y="277" fill="var(--color-text-2)">Visit 8</text><text x="440" y="277" text-anchor="end" fill="var(--color-text-1)">$2</text></g>
                    </g>
                    <rect x="330" y="282" width="154" height="34" rx="17" fill="var(--color-ink-900)" />
                    <text x="407" y="304" text-anchor="middle" font-size="13" font-weight="700" fill="var(--color-accent-400)">Cap: $99/month</text>
                </svg>
            </div>
        </div>
    </section>
</div>
