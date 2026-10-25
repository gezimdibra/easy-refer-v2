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
        $sizes = match ($variant) {
            'screen' => ['wrap' => 'max-w-md', 'headline' => 'font-size: 34px; line-height: 38px', 'qr' => 'w-80 h-80'],
            'counter' => ['wrap' => 'w-[148mm]', 'headline' => 'font-size: 38px; line-height: 40px', 'qr' => 'w-60 h-60'],
            'mini' => ['wrap' => 'w-[105mm]', 'headline' => 'font-size: 28px; line-height: 30px', 'qr' => 'w-[152px] h-[152px]'],
            'tent' => ['wrap' => 'max-w-[210mm]', 'headline' => 'font-size: 40px; line-height: 42px', 'qr' => 'w-[220px] h-[220px]'],
            'receipt' => ['wrap' => 'max-w-2xl', 'headline' => 'font-size: 20px; line-height: 22px', 'qr' => 'w-[144px] h-[144px]'],
            default => ['wrap' => 'max-w-2xl', 'headline' => 'font-size: 60px; line-height: 62px', 'qr' => 'w-[560px] h-[560px] max-w-full'],
        };

        // Per-variant density for the flowing sheets (screen,
        // counter, window, mini): inner padding, header logo,
        // the stack's margin rhythm, halo/frame padding, reward
        // card padding, and the band's padding, logo, and
        // caption size. Screen carries the original values
        // unchanged.
        $density = match ($variant) {
            'counter' => ['pad' => '20px 24px 0', 'logo' => 38, 'namept' => '8px', 'h1' => 12, 'sub' => 8, 'gg' => 8, 'steps' => 10, 'qr' => 14, 'scan' => 10, 'card' => 10, 'trust' => 10, 'band' => 14, 'halo' => 8, 'frame' => 16, 'cardpad' => '10px 20px', 'bandpad' => '10px 24px', 'bandlogo' => 32, 'bandcap' => '', 'bodycls' => 'type-body-lg'],
            'mini' => ['pad' => '12px 16px 0', 'logo' => 26, 'namept' => '2px', 'h1' => 8, 'sub' => 8, 'gg' => 4, 'steps' => 0, 'qr' => 10, 'scan' => 8, 'card' => 6, 'trust' => 0, 'band' => 8, 'halo' => 5, 'frame' => 10, 'cardpad' => '8px 14px', 'bandpad' => '8px 16px', 'bandlogo' => 20, 'bandcap' => 'font-size: 11px; line-height: 14px', 'bodycls' => 'type-body'],
            'window' => ['pad' => '28px 32px 0', 'logo' => 46, 'namept' => '8px', 'h1' => 28, 'sub' => 12, 'gg' => 12, 'steps' => 0, 'qr' => 28, 'scan' => 16, 'card' => 0, 'trust' => 0, 'band' => 28, 'halo' => 10, 'frame' => 20, 'cardpad' => '20px 24px', 'bandpad' => '20px 32px', 'bandlogo' => 32, 'bandcap' => '', 'bodycls' => 'type-body-lg'],
            default => ['pad' => '32px 32px 0', 'logo' => 46, 'namept' => '8px', 'h1' => 40, 'sub' => 16, 'gg' => 12, 'steps' => 32, 'qr' => 40, 'scan' => 16, 'card' => 32, 'trust' => 32, 'band' => 40, 'halo' => 10, 'frame' => 20, 'cardpad' => '20px 24px', 'bandpad' => '20px 32px', 'bandlogo' => 32, 'bandcap' => '', 'bodycls' => 'type-body-lg'],
        };

        // Which pass-2/3 elements each sheet variant carries:
        // steps where the reader has three seconds (screen,
        // counter), the YOUR REWARD card where the payoff can
        // be studied (counter, mini-poster; addendum A1), the
        // trust line as the closer (screen, counter). The
        // window keeps six-feet discipline: headline, give/get,
        // code, band, nothing finer.
        $showSteps = in_array($variant, ['screen', 'counter'], true);
        $showRewardCard = in_array($variant, ['counter', 'mini'], true);
        $showTrustLine = in_array($variant, ['screen', 'counter'], true);

        // F5 (print proof, strict reading of spec 8.5 item 1):
        // only the reward amount carries brand-600 in the
        // headline, not the whole reward text. The amount is the
        // leading money or percent figure ("$10", "10 percent",
        // "20%"); a reward with no leading figure colors its
        // first word instead, the one other word the spec
        // allows. The suffix comes from the shared composer
        // (F-2), so the first-visit phrase can never double,
        // and a trailing period drops before it exactly as the
        // composer drops it.
        $rewardText = trim((string) $business->friend_reward_text);
        $rewardSuffix = $business->friendRewardHeadlineSuffix();
        if ($rewardSuffix !== '') {
            $rewardText = rtrim($rewardText, '.');
        }
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
        $referrerText = rtrim(trim((string) $business->referrer_reward_text), '.');
        if (preg_match('/^(\$?\d+(?:[.,]\d+)?(?:\s*(?:%|percent\b))?)/i', $referrerText, $referrerMatch) === 1) {
            $referrerAmount = $referrerMatch[1];
            $referrerRest = substr($referrerText, strlen($referrerMatch[1]));
        } else {
            $referrerParts = preg_split('/\s+/', $referrerText, 2);
            $referrerAmount = $referrerParts[0] ?? '';
            $referrerRest = isset($referrerParts[1]) ? ' '.$referrerParts[1] : '';
        }
    @endphp
    <p class="poster-actions mx-auto {{ $sizes['wrap'] }} px-6 pt-6">
        <a class="btn btn-secondary" href="{{ route('owner.qr') }}">Back to your QR code</a>
    </p>
    @if ($variant === 'tent')
        <main>
            {{-- F-01: the faces are fixed 148mm boxes with
                 overflow hidden; the stack is trimmed (padding,
                 rhythm, frame) so the last line, "Scan to join",
                 sits inside the box on both faces. The geometry
                 test holds it there. --}}
            <section class="poster-sheet mx-auto my-6 overflow-hidden rounded-xl border border-line bg-surface-0 shadow-e2" style="width: 210mm; height: 148mm; padding: 20px">
                <div class="text-center" style="padding-top: 6px">
                    @if ($business->friend_reward_text)
                        <h1 class="text-text-1" style="{{ $sizes['headline'] }}; font-weight: 650; letter-spacing: -0.02em">Get <span class="text-brand-600">{{ $rewardAmount }}</span>{{ $rewardRest }}{{ $rewardSuffix }}</h1>
                    @else
                        <h1 class="text-text-1" style="{{ $sizes['headline'] }}; font-weight: 650; letter-spacing: -0.02em">Join {{ $business->name }}'s referral program</h1>
                    @endif
                    <p class="type-body-lg mt-4 font-semibold text-text-1">{{ $business->giveGetLine() }}</p>
                    <div class="mt-6">
                        <div class="poster-halo inline-block" style="padding: 8px; border-radius: 24px; background-color: #F5A300; box-shadow: 0 18px 48px rgba(245, 163, 0, 0.35)">
                            <div class="rounded-2xl" style="background-color: #ffffff; border: 1px solid #E7E0D2; padding: 10px">
                                <img src="{{ $imageUrl }}" alt="QR code for {{ $business->name }}" class="w-[220px] h-[220px] block">
                            </div>
                        </div>
                        <p class="mt-3 text-text-1" style="font-size: 17px; font-weight: 600">Scan to join</p>
                    </div>
                </div>
            </section>
            <section class="poster-sheet poster-page-break mx-auto my-6 overflow-hidden rounded-xl border border-line bg-surface-0 shadow-e2" style="width: 210mm; height: 148mm; padding: 20px">
                <div class="text-center" style="padding-top: 4px">
                    <p class="text-text-1" style="font-size: 34px; line-height: 38px; font-weight: 650; letter-spacing: -0.02em">{{ $business->name }}</p>
                    <p class="type-body mt-3 text-text-2"><span class="font-semibold text-text-1">1. Scan</span> · <span class="font-semibold text-text-1">2. Send it to a friend</span> · <span class="font-semibold text-text-1">3. You both get rewarded</span></p>
                    <p class="type-body-sm mt-2 text-text-2">Free to join. No app to install.</p>
                    <div class="mt-4">
                        <div class="poster-halo inline-block" style="padding: 8px; border-radius: 24px; background-color: #F5A300; box-shadow: 0 18px 48px rgba(245, 163, 0, 0.35)">
                            <div class="rounded-2xl" style="background-color: #ffffff; border: 1px solid #E7E0D2; padding: 10px">
                                <img src="{{ $imageUrl }}" alt="QR code for {{ $business->name }}" class="w-[220px] h-[220px] block">
                            </div>
                        </div>
                        <p class="mt-3 text-text-1" style="font-size: 17px; font-weight: 600">Scan to join</p>
                    </div>
                </div>
            </section>
        </main>
    @elseif ($variant === 'receipt')
        <main>
            <section class="poster-sheet mx-auto my-6 overflow-hidden rounded-xl border border-line bg-surface-0 shadow-e2" style="width: 85mm; height: 55mm; padding: 4mm; page: receipt-card">
                <div class="flex items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-text-1" style="font-size: 15px; line-height: 19px">Thanks for coming in.</p>
                        @if ($business->friend_reward_text)
                            <h1 class="mt-1 text-text-1" style="font-size: 17px; line-height: 20px; font-weight: 650; letter-spacing: -0.01em">Get <span class="text-brand-600">{{ $rewardAmount }}</span>{{ $rewardRest }}{{ $rewardSuffix }}</h1>
                        @else
                            <h1 class="mt-1 text-text-1" style="font-size: 17px; line-height: 20px; font-weight: 650; letter-spacing: -0.01em">Join {{ $business->name }}'s referral program</h1>
                        @endif
                        <p class="mt-1 font-semibold text-text-1" style="font-size: 10.5px; line-height: 14px">{{ $business->giveGetLine() }}</p>
                        <p class="mt-1 text-text-2" style="font-size: 9px; line-height: 12px">Free to join. No app to install.</p>
                    </div>
                    <div class="flex-none text-center">
                        <div class="poster-halo inline-block" style="padding: 4px; border-radius: 14px; background-color: #F5A300">
                            <div class="rounded-lg" style="background-color: #ffffff; border: 1px solid #E7E0D2; padding: 6px">
                                <img src="{{ $imageUrl }}" alt="QR code for {{ $business->name }}" class="block" style="width: 104px; height: 104px">
                            </div>
                        </div>
                        <p class="mt-1 font-semibold text-text-1" style="font-size: 9px; line-height: 12px">Scan to join</p>
                    </div>
                </div>
            </section>
            <section class="poster-sheet poster-page-break mx-auto my-6 overflow-hidden rounded-xl border border-line bg-surface-0 text-center shadow-e2" style="width: 80mm; padding: 4mm; page: receipt-strip">
                <p class="font-semibold text-text-1" style="font-size: 18px; line-height: 22px">Thanks for coming in.</p>
                @if ($business->friend_reward_text)
                    <h1 class="mt-1 text-text-1" style="font-size: 20px; line-height: 23px; font-weight: 650; letter-spacing: -0.01em">Get <span class="text-brand-600">{{ $rewardAmount }}</span>{{ $rewardRest }}{{ $rewardSuffix }}</h1>
                @else
                    <h1 class="mt-1 text-text-1" style="font-size: 20px; line-height: 23px; font-weight: 650; letter-spacing: -0.01em">Join {{ $business->name }}'s referral program</h1>
                @endif
                <p class="mt-2 font-semibold text-text-1" style="font-size: 12px; line-height: 16px">{{ $business->giveGetLine() }}</p>
                <div class="mt-3">
                    <div class="poster-halo inline-block" style="padding: 6px; border-radius: 18px; background-color: #F5A300">
                        <div class="rounded-xl" style="background-color: #ffffff; border: 1px solid #E7E0D2; padding: 10px">
                            <img src="{{ $imageUrl }}" alt="QR code for {{ $business->name }}" class="w-[144px] h-[144px] block">
                        </div>
                    </div>
                    <p class="mt-1 font-semibold text-text-1" style="font-size: 12px; line-height: 16px">Scan to join</p>
                </div>
                <p class="mt-2 text-text-2" style="font-size: 10px; line-height: 14px">Free to join. No app to install.</p>
            </section>
        </main>
    @else
    <main class="poster-sheet {{ $variant === 'counter' ? 'poster-counter' : ($variant === 'window' ? 'poster-window' : '') }} mx-auto {{ $sizes['wrap'] }} my-6 overflow-hidden rounded-xl border border-line bg-surface-0 shadow-e2">
        <div style="padding: {{ $density['pad'] }}">
            <div class="flex items-start justify-between gap-4">
                <x-logo :size="$density['logo']" />
                <p class="type-title-4 text-right text-text-1" style="padding-top: {{ $density['namept'] }}">{{ $business->name }}</p>
            </div>

            @if ($business->friend_reward_text)
                <h1 class="text-text-1" style="margin-top: {{ $density['h1'] }}px; {{ $sizes['headline'] }}; font-weight: 650; letter-spacing: -0.02em">Get <span class="text-brand-600">{{ $rewardAmount }}</span>{{ $rewardRest }}{{ $rewardSuffix }}</h1>
            @else
                <h1 class="text-text-1" style="margin-top: {{ $density['h1'] }}px; {{ $sizes['headline'] }}; font-weight: 650; letter-spacing: -0.02em">Join {{ $business->name }}'s referral program</h1>
            @endif
            <p class="{{ $density['bodycls'] }} text-text-2" style="margin-top: {{ $density['sub'] }}px">Scan this code to join and start referring.</p>
            <p class="{{ $density['bodycls'] }} font-semibold text-text-1" style="margin-top: {{ $density['gg'] }}px">{{ $business->giveGetLine() }}</p>

            @if ($showSteps)
                <p class="type-body-sm text-center text-text-2" style="margin-top: {{ $density['steps'] }}px"><span class="font-semibold text-text-1">1. Scan</span> · <span class="font-semibold text-text-1">2. Send it to a friend</span> · <span class="font-semibold text-text-1">3. You both get rewarded</span></p>
            @endif

            <div class="text-center" style="margin-top: {{ $density['qr'] }}px">
                <div class="poster-halo inline-block" style="padding: {{ $density['halo'] }}px; border-radius: 26px; background-color: #F5A300; box-shadow: 0 18px 48px rgba(245, 163, 0, 0.35)">
                    <div class="rounded-2xl" style="background-color: #ffffff; border: 1px solid #E7E0D2; padding: {{ $density['frame'] }}px">
                        <img src="{{ $imageUrl }}" alt="QR code for {{ $business->name }}" class="{{ $sizes['qr'] }} block">
                    </div>
                </div>
                <p class="text-text-1" style="margin-top: {{ $density['scan'] }}px; font-size: 17px; font-weight: 600">Scan to join</p>
            </div>

            @if ($showRewardCard && $referrerText !== '')
                <div class="mx-auto text-center" style="margin-top: {{ $density['card'] }}px; max-width: 380px; border: 2px solid #F5A300; border-radius: 14px; padding: {{ $density['cardpad'] }}">
                    <p class="type-caption uppercase text-text-2" style="letter-spacing: 0.18em">YOUR REWARD</p>
                    <p class="mt-2 text-text-1" style="font-size: 26px; line-height: 34px; font-weight: 600"><span class="text-brand-600" style="font-size: {{ $variant === 'counter' ? '48px' : '32px' }}; font-weight: 700">{{ $referrerAmount }}</span>{{ $referrerRest }}</p>
                </div>
            @endif

            @if ($showTrustLine)
                <p class="type-body-sm text-center text-text-2" style="margin-top: {{ $density['trust'] }}px">Free to join. No app to install.</p>
            @endif
        </div>

        <div class="on-dark flex items-center justify-between gap-4 bg-ink-950" style="margin-top: {{ $density['band'] }}px; padding: {{ $density['bandpad'] }}">
            <x-logo variant="reversed" :size="$density['bandlogo']" />
            {{-- F-10: the credit phrase never breaks mid-phrase;
                 the band wraps between the two sentences at most. --}}
            <p class="type-caption text-right" @if ($density['bandcap']) style="{{ $density['bandcap'] }}" @endif><span class="whitespace-nowrap text-white">Free to join. Rewards for sharing.</span> <span class="whitespace-nowrap text-dark-muted">Powered by Easy Refer.</span></p>
        </div>
    </main>
    @endif
    @if ($autoPrint)
        <script>window.addEventListener('load', () => window.print());</script>
    @endif
</body>
</html>
