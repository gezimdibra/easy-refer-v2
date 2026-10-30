<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Poster | {{ $business->name }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css'])
    @php
        // The paper each artifact opens at in the print dialog
        // (QR growth kit Section 3): set per variant so the
        // owner does not have to pick it by hand.
        $pageSize = match ($variant) {
            'counter' => 'A5 portrait',
            'window' => 'A4 portrait',
            'mini' => 'A6 portrait',
            'tent' => 'A5 landscape',
            'receipt' => '85mm 55mm',
            default => null,
        };
        // RC-3 Candidate F (Amendment 41): the multi-up sheet of
        // receipt cards prints on one Letter page.
        if ($variant === 'receipt' && ($multiUp ?? false)) {
            $pageSize = 'Letter portrait';
        }
    @endphp
    <style>
        @if ($pageSize)
        @page { size: {{ $pageSize }}; margin: 0; }
        @endif
        @if ($variant === 'receipt')
        @page receipt-card { size: 85mm 55mm; margin: 0; }
        @page receipt-strip { size: 80mm 120mm; margin: 0; }
        @endif
        @media print {
            .poster-actions { display: none; }
            body { background: #ffffff; }
            /* Train 1 fix round (F-01..F-04): the sheets' screen
               margins (my-6) must not survive into print, where
               they push a page-sized sheet past its own paper.
               Zero by construction in every engine; the print
               pagination test pins it. */
            .poster-sheet { border: 0 !important; box-shadow: none !important; margin-top: 0 !important; margin-bottom: 0 !important; }
            /* R4-U5: the screen halo is a soft shadow; in print it
               flattens to the solid amber ring (some engines drop
               shadows, some smear them; the ring alone is the
               designed print object). */
            .poster-halo { box-shadow: none !important; }
            .poster-page-break { break-before: page; }
        }
    </style>
</head>
<body class="bg-surface-1 text-text-1 theme-light-lock">
    {{-- The printed poster set (redesign-spec.md Section 8.5;
         QR growth kit Sections 2-3, copy REF-POSTER-02). Every
         artifact is read in three passes: pass 1 (six feet) the
         business name and ONE reward headline; pass 2 (three
         feet) the give/get line and, where the reader has time,
         the steps strip; pass 3 (arm's length) the trust line.
         The code stays pure black on white; the amber halo
         frame around it carries the brand (addendum A3). The
         closer is the trust line: an urgency line renders only
         when a real boost window is active, and boost windows
         do not exist yet (addendum A2). The Back action lives
         outside the sheet and never prints. --}}
    @php
        // Train 1 fix round (F-02/F-03/F-04): each sheet variant
        // is scaled to fit the paper its @page names — the
        // counter uses the full A5 width, the mini the full A6
        // width, and type/code sizes step down per sheet while
        // the pass hierarchy (headline > give/get > steps >
        // trust) is preserved. The print pagination + geometry
        // tests (PosterPrintFitTest) hold every variant to its
        // page; do not grow a stack without re-measuring.
        // Amendment 31 [ON-QR-1]: the screen variant is
        // retired (it 404s in the controller, like any unknown
        // variant); no screen entry remains in these maps.
        $sizes = match ($variant) {
            'counter' => ['wrap' => 'w-[148mm]', 'headline' => 'font-size: 34px; line-height: 37px', 'qr' => 'w-60 h-60'],
            'mini' => ['wrap' => 'w-[105mm]', 'headline' => 'font-size: 25px; line-height: 27px', 'qr' => 'w-[152px] h-[152px]'],
            'tent' => ['wrap' => 'max-w-[210mm]', 'headline' => 'font-size: 40px; line-height: 42px', 'qr' => 'w-[220px] h-[220px]'],
            'receipt' => ['wrap' => 'max-w-2xl', 'headline' => 'font-size: 20px; line-height: 22px', 'qr' => 'w-[144px] h-[144px]'],
            default => ['wrap' => 'max-w-2xl', 'headline' => 'font-size: 60px; line-height: 62px', 'qr' => 'w-[560px] h-[560px] max-w-full'],
        };

        // Per-variant density for the flowing sheets (counter,
        // window, mini): inner padding, header logo, the stack's
        // margin rhythm, halo/frame padding, reward card
        // padding, and the band's padding, logo, and caption
        // size. The default carries the original values
        // unchanged.
        $density = match ($variant) {
            // Amendment 58: the counter rhythm trims (h1 12->6,
            // steps 10->6, qr 14->10, band 14->12) fund the
            // longer give/get headline — the named pagination
            // check measured the untrimmed stack at 805px
            // against the 794px A5 page; the retired subline +
            // pass-2 line paid for most of it, these trims pay
            // the rest. PosterPrintFitTest holds the sheet to
            // its page.
            // Amendment 58, worst-case round: with a long
            // spelled-out reward ("20 percent off your first
            // visit", the owner's own text class) the unified
            // headline + YOUR REWARD card measured 863px on
            // the counter and 583px on the mini (559px page).
            // The small sheets step their headline type down
            // (counter 38->34px, mini 28->25px), the reward
            // card's type trims (26/34 -> 24/30, amounts
            // 48/32 -> 40/28), and the counter/mini margins
            // trim as recorded in the maps below. The named
            // worst-case check (both configurations, every
            // variant) holds all of it.
            'counter' => ['pad' => '16px 24px 0', 'logo' => 38, 'namept' => '8px', 'h1' => 6, 'sub' => 8, 'gg' => 8, 'steps' => 4, 'qr' => 6, 'scan' => 10, 'card' => 6, 'trust' => 6, 'band' => 12, 'halo' => 8, 'frame' => 14, 'cardpad' => '10px 20px', 'bandpad' => '8px 24px', 'bandlogo' => 32, 'bandcap' => '', 'bodycls' => 'type-body-lg'],
            'mini' => ['pad' => '4px 16px 0', 'logo' => 26, 'namept' => '2px', 'h1' => 8, 'sub' => 8, 'gg' => 4, 'steps' => 0, 'qr' => 4, 'scan' => 4, 'card' => 6, 'trust' => 0, 'band' => 4, 'halo' => 4, 'frame' => 8, 'cardpad' => '8px 14px', 'bandpad' => '8px 16px', 'bandlogo' => 20, 'bandcap' => 'font-size: 11px; line-height: 14px', 'bodycls' => 'type-body'],
            // Amendment 58: the window rhythm trims (pad top
            // 16->8, h1 24->12, qr 24->12, scan 16->12, band
            // 16->8, bandpad 20->16 vertical) fund the longer
            // give/get headline at the window's 60px pass-1
            // size — the named pagination check measured the
            // untrimmed stack at 1167.5px against the 1123px
            // A4 page. Type sizes are untouched (six-feet
            // discipline is the window's job).
            'window' => ['pad' => '8px 32px 0', 'logo' => 46, 'namept' => '8px', 'h1' => 12, 'sub' => 12, 'gg' => 12, 'steps' => 0, 'qr' => 12, 'scan' => 12, 'card' => 0, 'trust' => 0, 'band' => 8, 'halo' => 10, 'frame' => 20, 'cardpad' => '20px 24px', 'bandpad' => '16px 32px', 'bandlogo' => 32, 'bandcap' => '', 'bodycls' => 'type-body-lg', 'cant' => 14],
            default => ['pad' => '32px 32px 0', 'logo' => 46, 'namept' => '8px', 'h1' => 40, 'sub' => 16, 'gg' => 12, 'steps' => 32, 'qr' => 40, 'scan' => 16, 'card' => 32, 'trust' => 32, 'band' => 40, 'halo' => 10, 'frame' => 20, 'cardpad' => '20px 24px', 'bandpad' => '20px 32px', 'bandlogo' => 32, 'bandcap' => '', 'bodycls' => 'type-body-lg'],
        };

        // Which pass-2/3 elements each sheet variant carries:
        // steps where the reader has three seconds (counter),
        // the YOUR REWARD card where the payoff can be studied
        // (counter, mini-poster; addendum A1), the trust line
        // as the closer (counter). The window keeps six-feet
        // discipline: headline, give/get, code, band, nothing
        // finer.
        $showSteps = in_array($variant, ['counter'], true);
        $showRewardCard = in_array($variant, ['counter', 'mini'], true);
        $showTrustLine = in_array($variant, ['counter'], true);

        // Amendment 58 [REF-POSTER-04] (owner ruling
        // 2026-10-29, Finding 4): the poster headline is the
        // give/get sentence composed by
        // Business::posterHeadline() — it leads with the
        // reward and the easy action, and "join" / "program"
        // never print in a headline or CTA. (Release 37's
        // reward-alone headline and the "Join {name}'s
        // referral program" fallback are both retired by
        // this amendment.) The amount/rest split below is
        // presentation only (F5 coloring); the composed text
        // (prefix + amount + rest + timing clause) IS the
        // composer output, byte for byte once tags are
        // removed. F5 (print proof, strict reading of spec
        // 8.5 item 1): only the reward amount carries
        // brand-600 in the headline, not the whole reward
        // text. The amount is the leading money or percent
        // figure ("$10", "10 percent", "20%"); a reward with
        // no leading figure colors its first word instead,
        // the one other word the spec allows. The text is
        // normalized the composer's way (trailing period
        // stripped), so no period can double against the
        // headline's own punctuation.
        $rewardText = trim(rtrim(trim((string) $business->friend_reward_text), '.'));
        $headlineSplit = $rewardText !== '' && $business->rewardsAreSplit();
        if (preg_match('/^(\$?\d+(?:[.,]\d+)?(?:\s*(?:%|percent\b))?)/i', $rewardText, $rewardMatch) === 1) {
            $rewardAmount = $rewardMatch[1];
            $rewardRest = substr($rewardText, strlen($rewardMatch[1]));
        } else {
            $rewardParts = preg_split('/\s+/', $rewardText, 2);
            $rewardAmount = $rewardParts[0] ?? '';
            $rewardRest = isset($rewardParts[1]) ? ' '.$rewardParts[1] : '';
        }

        // The YOUR REWARD card shows the scanner's own reward
        // (the referrer text), split the same way: the amount
        // figure largest on the page, the rest of the text
        // beside it (addendum A1). A trailing period is card
        // punctuation and drops.
        // OR-1 §1.3 D2 (Amendment 49): the card's value is the
        // RESOLVER's referrer text, so it renders whenever a
        // reward is set — UNIFIED (the unified value) and SPLIT
        // (the referrer text) alike. Before OR-1 it keyed on the
        // referrer COLUMN, so a fallback business printed NO
        // your-reward card even though its referrer was promised
        // the friend text.
        $referrerText = rtrim(trim((string) ($business->effectiveReferrerRewardText() ?? '')), '.');
        if (preg_match('/^(\$?\d+(?:[.,]\d+)?(?:\s*(?:%|percent\b))?)/i', $referrerText, $referrerMatch) === 1) {
            $referrerAmount = $referrerMatch[1];
            $referrerRest = substr($referrerText, strlen($referrerMatch[1]));
        } else {
            $referrerParts = preg_split('/\s+/', $referrerText, 2);
            $referrerAmount = $referrerParts[0] ?? '';
            $referrerRest = isset($referrerParts[1]) ? ' '.$referrerParts[1] : '';
        }
    @endphp
    {{-- MIX-1 preview mode: the QR page embeds this view as the
         Start here poster's scaled live preview; the screen
         chrome (Back action, inactive gate) is not part of the
         sheet and never renders inside the preview frame. --}}
    @unless ($preview ?? false)
        <p class="poster-actions mx-auto {{ $sizes['wrap'] }} px-6 pt-6">
            <a class="btn btn-secondary" href="{{ route('owner.qr') }}">Back to your QR code</a>
        </p>
        {{-- G1 (coherence Phase 2): the print surface carries the
             same inactive notice as the QR page, in the screen
             chrome only. The .poster-actions never prints, so the
             sheet itself stays a clean customer artifact. --}}
        @unless ($business->flowIsActive())
            <div class="poster-actions mx-auto {{ $sizes['wrap'] }} px-6 pt-4">
                <div class="gate">
                    @include('partials.qr-inactive-notice')
                </div>
            </div>
        @endunless
    @endunless
    @if ($variant === 'tent')
        <main>
            {{-- F-01: the faces are fixed 148mm boxes with
                 overflow hidden; the stack is trimmed (padding,
                 rhythm, frame) so the last line, "Scan to share",
                 sits inside the box on both faces. The geometry
                 test holds it there. Amendment 58: the headline
                 IS the give/get sentence now, so the old pass-2
                 give/get line no longer renders under it (the
                 same sentence twice is not a stack). --}}
            <section class="poster-sheet mx-auto my-6 overflow-hidden rounded-xl border border-line bg-surface-0 shadow-e2" style="width: 210mm; height: 148mm; padding: 20px">
                <div class="text-center" style="padding-top: 6px">
                    <h1 class="text-text-1" style="{{ $sizes['headline'] }}; font-weight: 650; letter-spacing: -0.02em">@include('owner.partials.poster-headline')</h1>
                    <div class="mt-6">
                        <div class="poster-halo inline-block" style="padding: 8px; border-radius: 24px; background-color: #F5A300; box-shadow: 0 18px 48px rgba(245, 163, 0, 0.35)">
                            <div class="rounded-2xl" style="background-color: #ffffff; border: 1px solid #E7E0D2; padding: 10px">
                                <img src="{{ $imageUrl }}" alt="QR code for {{ $business->name }}" class="w-[220px] h-[220px] block">
                            </div>
                        </div>
                        <p class="mt-3 text-text-1" style="font-size: 17px; font-weight: 600">Scan to share</p>
                    </div>
                </div>
            </section>
            <section class="poster-sheet poster-page-break mx-auto my-6 overflow-hidden rounded-xl border border-line bg-surface-0 shadow-e2" style="width: 210mm; height: 148mm; padding: 20px">
                <div class="text-center" style="padding-top: 2px">
                    <p class="text-text-1" style="font-size: 34px; line-height: 38px; font-weight: 650; letter-spacing: -0.02em">{{ $business->name }}</p>
                    <p class="type-body text-text-2" style="margin-top: 8px"><span class="font-semibold text-text-1">1. Scan</span> · <span class="font-semibold text-text-1">2. Share with a friend</span> · <span class="font-semibold text-text-1">3. You both get rewarded</span></p>
                    <p class="type-body-sm text-text-2" style="margin-top: 4px">No app to install.</p>
                    {{-- QRK-06 (REF-POSTER-02j): the typed fallback
                         beside the trust line. Face B's rhythm
                         trims above (steps, trust, code block,
                         frame) fund its height inside the fixed
                         face box; the geometry test holds it. --}}
                    <p class="text-text-2" style="margin-top: 4px; font-size: 11px; line-height: 15px">Can't scan? Go to {{ $joinDisplay }}</p>
                    <div style="margin-top: 10px">
                        <div class="poster-halo inline-block" style="padding: 8px; border-radius: 24px; background-color: #F5A300; box-shadow: 0 18px 48px rgba(245, 163, 0, 0.35)">
                            <div class="rounded-2xl" style="background-color: #ffffff; border: 1px solid #E7E0D2; padding: 8px">
                                <img src="{{ $imageUrl }}" alt="QR code for {{ $business->name }}" class="w-[220px] h-[220px] block">
                            </div>
                        </div>
                        <p class="mt-3 text-text-1" style="font-size: 17px; font-weight: 600">Scan to share</p>
                    </div>
                </div>
            </section>
        </main>
    @elseif ($variant === 'receipt')
        <main>
            @if ($multiUp ?? false)
                {{-- RC-3 Candidate F (Amendment 41): a sheet of
                     cards — the SAME card composition (the shared
                     partial), 8 up on one Letter page with light
                     dashed cut guides, so "print a stack" is a
                     single print job on a home printer. --}}
                <section class="poster-sheet mx-auto my-6 overflow-hidden border border-line bg-surface-0 shadow-e2" style="width: 190mm; padding: 5mm">
                    <div class="flex flex-wrap" style="gap: 4mm">
                        @for ($i = 0; $i < 8; $i++)
                            <div class="cut-guide overflow-hidden" style="width: 85mm; height: 55mm; padding: 2mm 4mm; border: 1px dashed #C9C2B4">
                                @include('owner.partials.receipt-card')
                            </div>
                        @endfor
                    </div>
                </section>
            @else
            {{-- RC-3 Candidate E (Amendment 41): one guidance line
                 per sheet in the screen chrome only (.poster-actions
                 never prints): which sheet is for what. --}}
            <p class="poster-actions mx-auto max-w-2xl px-6 pt-4 type-body text-text-2">The card: hand this out with the receipt.</p>
            <section class="poster-sheet mx-auto my-6 overflow-hidden rounded-xl border border-line bg-surface-0 shadow-e2" style="width: 85mm; height: 55mm; padding: 2mm 4mm; page: receipt-card">
                @include('owner.partials.receipt-card')
            </section>
            <p class="poster-actions mx-auto max-w-2xl px-6 pt-2 type-body text-text-2">The strip: same card, sized for a receipt printer roll.</p>
            <section class="poster-sheet poster-page-break mx-auto my-6 overflow-hidden rounded-xl border border-line bg-surface-0 text-center shadow-e2" style="width: 80mm; padding: 4mm; page: receipt-strip">
                <p class="font-semibold text-text-1" style="font-size: 18px; line-height: 22px">Thanks for coming in.</p>
                <h1 class="mt-1 text-text-1" style="font-size: 20px; line-height: 23px; font-weight: 650; letter-spacing: -0.01em">@include('owner.partials.poster-headline')</h1>
                <div class="mt-3">
                    <div class="poster-halo inline-block" style="padding: 6px; border-radius: 18px; background-color: #F5A300">
                        <div class="rounded-xl" style="background-color: #ffffff; border: 1px solid #E7E0D2; padding: 10px">
                            <img src="{{ $imageUrl }}" alt="QR code for {{ $business->name }}" class="w-[144px] h-[144px] block">
                        </div>
                    </div>
                    <p class="mt-1 font-semibold text-text-1" style="font-size: 12px; line-height: 16px">Scan to share</p>
                </div>
                <p class="mt-2 text-text-2" style="font-size: 10px; line-height: 14px">No app to install.</p>
                <p class="mt-1 text-text-2" style="font-size: 10px; line-height: 14px">Can't scan? Go to {{ $joinDisplay }}</p>
            </section>
            @endif
        </main>
    @else
    <main class="poster-sheet {{ $variant === 'counter' ? 'poster-counter' : ($variant === 'window' ? 'poster-window' : '') }} mx-auto {{ $sizes['wrap'] }} my-6 overflow-hidden rounded-xl border border-line bg-surface-0 shadow-e2">
        {{-- R4-U5: the floating logo/name header row becomes a
             full-bleed ink header strip (logo reversed, business
             name in cream at the pass-1 size), the same ink as the
             foot band, so a detached sheet still names its shop at
             the top. --}}
        <div class="on-dark flex items-center justify-between gap-4 bg-ink-950" style="padding: {{ $density['bandpad'] }}">
            <x-logo variant="reversed" :size="$density['bandlogo']" />
            <p class="type-title-4 text-right text-white">{{ $business->name }}</p>
        </div>
        <div style="padding: {{ $density['pad'] }}">

            {{-- Amendment 58 [REF-POSTER-04]: the headline is
                 the whole offer now (reward + action + timing),
                 so the two lines that used to sit under the old
                 reward-alone headline are retired from the
                 flowing sheets: the "Scan this code to join and
                 start referring." subline (REF-POSTER-02i,
                 superseded) and the pass-2 give/get line (the
                 headline IS that sentence). The steps strip,
                 where the variant carries one, keeps the scan
                 instruction. --}}
            <h1 class="text-text-1" style="margin-top: {{ $density['h1'] }}px; {{ $sizes['headline'] }}; font-weight: 650; letter-spacing: -0.02em">@include('owner.partials.poster-headline')</h1>

            @if ($showSteps)
                <p class="type-body-sm text-center text-text-2" style="margin-top: {{ $density['steps'] }}px"><span class="font-semibold text-text-1">1. Scan</span> · <span class="font-semibold text-text-1">2. Share with a friend</span> · <span class="font-semibold text-text-1">3. You both get rewarded</span></p>
            @endif

            <div class="text-center" style="margin-top: {{ $density['qr'] }}px">
                <div class="poster-halo inline-block" style="padding: {{ $density['halo'] }}px; border-radius: 26px; background-color: #F5A300; box-shadow: 0 18px 48px rgba(245, 163, 0, 0.35)">
                    <div class="rounded-2xl" style="background-color: #ffffff; border: 1px solid #E7E0D2; padding: {{ $density['frame'] }}px">
                        <img src="{{ $imageUrl }}" alt="QR code for {{ $business->name }}" class="{{ $sizes['qr'] }} block">
                    </div>
                </div>
                <p class="text-text-1" style="margin-top: {{ $density['scan'] }}px; font-size: 17px; font-weight: 600">Scan to share</p>
            </div>

            @if ($showRewardCard && $referrerText !== '')
                <div class="mx-auto text-center" style="margin-top: {{ $density['card'] }}px; max-width: 380px; border: 2px solid #F5A300; border-radius: 14px; padding: {{ $density['cardpad'] }}">
                    <p class="type-caption uppercase text-text-2" style="letter-spacing: 0.18em">YOUR REWARD</p>
                    <p class="mt-2 text-text-1" style="font-size: 24px; line-height: 30px; font-weight: 600"><span class="text-brand-600" style="font-size: {{ $variant === 'counter' ? '40px' : '28px' }}; font-weight: 700">{{ $referrerAmount }}</span>{{ $referrerRest }}</p>
                </div>
            @endif

            @if ($showTrustLine)
                <p class="type-body-sm text-center text-text-2" style="margin-top: {{ $density['trust'] }}px">No app to install.</p>
            @endif

            {{-- QRK-06 (REF-POSTER-02j): the typed fallback, on
                 every flowing sheet including the ones with no
                 trust-line element (window, mini). The address
                 renders from the app's own base URL at print
                 time, so it resolves from this deployment. The
                 mini/window density trims above fund its height;
                 PosterPrintFitTest holds the sheets to their
                 pages. --}}
            {{-- R4-U5: on the window sheet the fallback is read
                 through glass at distance; it steps up to 14px
                 (the density 'cant' key; other sheets keep 11px). --}}
            <p class="text-center text-text-2" style="margin-top: 6px; font-size: {{ $density['cant'] ?? 11 }}px; line-height: {{ ($density['cant'] ?? 11) + 4 }}px">Can't scan? Go to {{ $joinDisplay }}</p>
        </div>

        <div class="on-dark flex items-center justify-between gap-4 bg-ink-950" style="margin-top: {{ $density['band'] }}px; padding: {{ $density['bandpad'] }}">
            <x-logo variant="reversed" :size="$density['bandlogo']" />
            {{-- F-10: the credit phrase never breaks mid-phrase;
                 the band wraps between the two sentences at most. --}}
            <p class="type-caption text-right" @if ($density['bandcap']) style="{{ $density['bandcap'] }}" @endif><span class="whitespace-nowrap text-white">Rewards for sharing.</span> <span class="whitespace-nowrap text-dark-muted">Powered by Easy Refer.</span></p>
        </div>
    </main>
    @endif
    @if ($autoPrint)
        <script>window.addEventListener('load', () => window.print());</script>
    @endif
</body>
</html>
