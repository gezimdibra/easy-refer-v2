@extends('layouts.flow')

@section('title', 'Your reward | Easy Refer')
@section('flow_label', 'Redemption')
@if ($state === 'show')
    @section('step')
        <x-state-pill state="ready" />
    @endsection
@endif
@if ($state === 'redeemed')
    @section('main_class', 'mt-3')
@endif

@section('content')
    {{-- R7, the friend's redemption page (spec Section 3 Step 9,
         inventory REF-RDM): the full state in every variant: the
         show-this screen, the redeemed receipt (the Section 3.5
         flood, R8b's rule that the customer's own screen shows the
         same receipt state when next viewed), the expired page.
         (Option B, 2026-10-28: this page has NO held variant —
         the friend never waits for a review outcome.) --}}
    @if ($state === 'show')
        <div class="flex items-center gap-3">
            <span class="flex h-11 w-11 flex-none items-center justify-center rounded-lg bg-brand-50 text-text-1" aria-hidden="true"><span style="font-size: 18px; font-weight: 650">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($business->name, 0, 1)) }}</span></span>
            <span>
                <span class="type-title-4 block text-text-1">{{ $business->name }}</span>
                @if (filled($business->street))
                    <span class="type-caption block text-text-2">{{ $business->street }}, {{ $business->city }}</span>
                @endif
            </span>
        </div>
        {{-- R4-U2 (Surface 1 pick C): the prize is the headline, in
             the poster's own composed sentence (uncolored on
             screen per the pack's token-trap note: brand-600 is
             ink and vanishes on the dark card), then the code
             alone on its stage. F-UP-1 two-key Finding 6: the
             sentence is composed from THIS referral's promised
             text (Referral::promisedFriendRewardText(), whose
             LEGACY LIVE-CONFIG FALLBACK already covers
             pre-snapshot rows), NOT live config, so after a
             mid-flight reward edit this page and the staff
             confirm screen promise the same reward. The
             first-visit suffix logic is the one Business::
             friendRewardHeadline() applies, evaluated on the
             promised text itself: the suffix is appended (after
             dropping a trailing period) only when the promised
             text never mentions a first anything. --}}
        @php
            $promisedRewardText = $referral->promisedFriendRewardText();
            $promisedRewardHeadline = null;
            if ($promisedRewardText !== null) {
                $promisedRewardHeadline = preg_match('/\bfirst\b/i', $promisedRewardText) === 1
                    ? $promisedRewardText
                    : rtrim($promisedRewardText, '.').' on your first visit';
            }
        @endphp
        @if ($promisedRewardHeadline)
            <h1 class="type-display-xs mt-5">{{ $promisedRewardHeadline }}</h1>
        @else
            <h1 class="type-display-xs mt-5">Show this at {{ $business->name }}</h1>
        @endif

        <p class="mt-3 font-semibold text-text-1">Show this code to a staff member. They scan it with their own phone.</p>

        <div class="mt-5 text-center">
            <span class="qr-stage"><img src="{{ $qrUrl }}" alt="Your redemption QR code" width="248" height="248"></span>
        </div>

        <p class="mt-4 text-center text-text-2">Good until {{ $business->localTime($referral->expires_at)->format('F j, Y') }}.</p>
    @elseif ($state === 'redeemed')
        {{-- TWO-KEY FLAG (F-UP-1): reward-truth hunk. The
             friend's redeemed receipt renders the claim-time
             snapshot (promisedFriendRewardText(), with its
             named LEGACY LIVE-CONFIG FALLBACK), not live
             config, so re-viewing the receipt after an edit
             still shows what was redeemed. --}}
        @include('partials.success-flood', [
            'rewardText' => $referral->promisedFriendRewardText() ?? 'Reward redeemed',
            'businessName' => $business->name,
            'business' => $business,
            'outcome' => 'Reward redeemed',
            'confirmedAt' => $referral->redeemed_at,
            'note' => 'Keep this screen as your receipt.',
        ])

        @if ($prime ?? null)
            {{-- RS-N2 (Train B, Amendment 39): the contagion
                 prime, below the receipt facts. This branch is
                 the ONLY place it can render: the controller
                 builds the payload solely for the redeemed state
                 of a live flow, so the expired and
                 unavailable branches below, every staff screen,
                 and the claim receipt can never carry it. The
                 share action is the receipt's primary next
                 action; the review ask is demoted to a link
                 below it (never deleted). --}}
            <div class="well mt-5">
                <h2 class="type-title-3">Pass it on</h2>
                <p class="mt-2 text-text-2">You found {{ $business->name }} through {{ $prime['referrerFirstName'] }}.</p>
                <p class="mt-2 text-text-2">{{ $prime['offerLine'] }}</p>
                @include('flow._share-actions', [
                    'shareUrl' => $prime['shareUrl'],
                    'shareMessage' => $prime['shareMessage'],
                    'fieldLabel' => 'Your link',
                    'fieldId' => 'prime',
                ])
            </div>
        @endif

        {{-- ID-05 (Amendment 48, Variant B): the ask as a
             full-width secondary button below the prime, in
             the claim receipt's exact form, gated and recorded
             by ReviewAsk (the controller's $reviewAsk). Train
             B's demoted plain link is superseded by this
             deliberate form; the prime stays the receipt's
             only primary action, above the ask. --}}
        @if ($reviewAsk ?? false)
            @include('flow._review-ask')
        @endif
    @elseif ($state === 'expired')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <p class="mt-3"><x-state-pill state="expired" /></p>
        <h1 class="type-display-xs mt-3">This reward expired on {{ $business->localTime($referral->expires_at)->format('F j, Y') }}</h1>
        <p class="mt-2 text-text-2">Rewards are good for {{ $business->redemption_window_days }} days.</p>

        @if ($backUrl ?? null)
            <p class="mt-5"><a class="btn btn-secondary" href="{{ $backUrl }}">Back to {{ $business->name }}</a></p>
        @endif
    @elseif ($state === 'ended')
        {{-- C-03 (FE-CANCEL-01): the claimed-but-unvisited
             friend whose shop ended its program. Names no date
             and no blame; renders only for referrals voided by
             the ending itself. --}}
        <h1 class="type-display-xs">This shop has ended its referral program, so this reward is no longer available.</h1>
    @else
        <h1 class="type-display-xs">This code is not active</h1>
        <p class="mt-2 text-text-2">Please ask the business for help, or check that you scanned the whole code.</p>
    @endif
@endsection
