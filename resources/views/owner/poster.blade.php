<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Poster | {{ $business->name }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            .poster-actions { display: none; }
            body { background: #ffffff; }
            .poster-sheet { border: 0 !important; box-shadow: none !important; }
        }
    </style>
</head>
<body class="bg-surface-1 text-text-1">
    {{-- The printed poster (redesign-spec.md Section 8.5): the
         brand travels with the code. Top row: the lockup and the
         business name. Headline: the friend reward, the one
         message that makes a stranger scan. The code in pure
         black on white inside its frame, "Scan to join" beneath.
         Bottom: the ink-950 band with the reversed lockup. The
         Back action lives outside the sheet and never prints
         (visual audit P-1 to P-5). --}}
    @php
        $sizes = match ($variant) {
            'screen' => ['wrap' => 'max-w-md', 'headline' => 'font-size: 34px; line-height: 38px', 'qr' => 'w-80 h-80'],
            'counter' => ['wrap' => 'max-w-lg', 'headline' => 'font-size: 44px; line-height: 46px', 'qr' => 'w-96 h-96'],
            default => ['wrap' => 'max-w-2xl', 'headline' => 'font-size: 60px; line-height: 62px', 'qr' => 'w-[560px] h-[560px] max-w-full'],
        };

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
    @endphp
    <p class="poster-actions mx-auto {{ $sizes['wrap'] }} px-6 pt-6">
        <a class="btn btn-secondary" href="{{ route('owner.qr') }}">Back to your QR code</a>
    </p>
    <main class="poster-sheet {{ $variant === 'counter' ? 'poster-counter' : ($variant === 'window' ? 'poster-window' : '') }} mx-auto {{ $sizes['wrap'] }} my-6 overflow-hidden rounded-xl border border-line bg-surface-0 shadow-e2">
        <div style="padding: 32px 32px 0">
            <div class="flex items-start justify-between gap-4">
                <x-logo :size="46" />
                <p class="type-title-4 pt-2 text-right text-text-1">{{ $business->name }}</p>
            </div>

            @if ($business->friend_reward_text)
                <h1 class="mt-10 text-text-1" style="{{ $sizes['headline'] }}; font-weight: 650; letter-spacing: -0.02em">Get <span class="text-brand-600">{{ $rewardAmount }}</span>{{ $rewardRest }}{{ $rewardSuffix }}</h1>
            @else
                <h1 class="mt-10 text-text-1" style="{{ $sizes['headline'] }}; font-weight: 650; letter-spacing: -0.02em">Join {{ $business->name }}'s referral program</h1>
            @endif
            <p class="type-body-lg mt-4 text-text-2">Scan this code to join and start referring.</p>

            <div class="mt-10 text-center">
                <div class="inline-block rounded-xl border border-line bg-surface-0" style="padding: 20px">
                    <img src="{{ $imageUrl }}" alt="QR code for {{ $business->name }}" class="{{ $sizes['qr'] }} block">
                </div>
                <p class="mt-4 text-text-1" style="font-size: 17px; font-weight: 600">Scan to join</p>
            </div>
        </div>

        <div class="on-dark mt-10 flex items-center justify-between gap-4 bg-ink-950" style="padding: 20px 32px">
            <x-logo variant="reversed" :size="32" />
            <p class="type-caption text-right"><span class="text-white">Free to join. Rewards for sharing.</span> <span class="text-dark-muted">Powered by Easy Refer.</span></p>
        </div>
    </main>
    @if ($autoPrint)
        <script>window.addEventListener('load', () => window.print());</script>
    @endif
</body>
</html>
