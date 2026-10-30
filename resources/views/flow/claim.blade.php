@extends('layouts.flow')

@section('title', 'Your reward | Easy Refer')
@section('flow_label', 'Reward')
@if ($state === 'ready')
    {{-- R4-U1: the Ready pill rides the flow chrome row (the step
         slot), so the state reads before the card does. --}}
    @section('step')
        <x-state-pill state="ready" />
    @endsection
@endif
@if ($state === 'claimed')
    @section('main_class', 'mt-3')
@endif

@section('content')
    {{-- R9, the referrer's claim page (spec Section 6.1 item 5,
         inventory RDM-CLAIM): every state; the availability date
         only inside the referrer's own live flow session (G-17),
         which the controller decides, never this template. The
         claimed state is the Section 3.5 receipt flood, the same
         receipt the staff phone showed at the counter. --}}
    @if ($state === 'ready')
        <div class="flex items-center gap-3">
            <span class="flex h-11 w-11 flex-none items-center justify-center rounded-lg bg-brand-50 text-text-1" aria-hidden="true"><span style="font-size: 18px; font-weight: 650">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($business->name, 0, 1)) }}</span></span>
            <span>
                <span class="type-title-4 block text-text-1">{{ $business->name }}</span>
                @if (filled($business->street))
                    <span class="type-caption block text-text-2">{{ $business->street }}, {{ $business->city }}</span>
                @endif
            </span>
        </div>
        {{-- R4-U2 (Surface 1 pick C): one grammar with the redeem
             show screen: the prize as the headline (the snapshot,
             uncolored per the token-trap note), one semibold
             instruction line, the code alone on its stage. --}}
        <h1 class="type-display-xs mt-5">{{ $reward->reward_text_snapshot }}</h1>
        <p class="mt-3 font-semibold text-text-1">Show this code to a staff member. They scan it with their own phone.</p>
        {{-- M-4 [M4-CLAIM-01]: the use-by date, ready state only,
             the same words the friend redeem page uses for its
             own window. Exact date on bare links too: an expiry
             date is not the session-gated fact the vesting date
             is under G-17 (pass Section 2.2). --}}
        @if ($reward->expires_at)
            <p class="mt-2 text-text-2">Good until {{ $business->localTime($reward->expires_at)->format('F j, Y') }}.</p>
        @endif

        <div class="mt-5 text-center">
            <span class="qr-stage"><img src="{{ $qrUrl }}" alt="Your reward QR code" width="248" height="248"></span>
        </div>

        {{-- ID-07 [RR-CP-01] (Amendment 51): the reshare block,
             below the QR stage: the page's one job (showing
             the code) keeps the top; facts first, ask second.
             The controller builds the payload solely for the
             ready state of a live flow with a live share link,
             so no other claim state can carry it. For the
             came-as-a-friend cohort, [F2R-03] is the block's
             first line, before the share action. --}}
        @if ($reshare ?? null)
            <div class="well mt-5">
                @if ($reshare['cameAsFriend'])
                    <p class="text-text-2">You came in through a friend yourself.</p>
                @endif
                @include('flow._share-actions', [
                    'shareUrl' => $reshare['shareUrl'],
                    'shareMessage' => $reshare['shareMessage'],
                    'fieldId' => 'reshare',
                ])
            </div>
        @endif
    @elseif ($state === 'claimed')
        @include('partials.success-flood', [
            'rewardText' => $reward->reward_text_snapshot,
            'businessName' => $business->name,
            'business' => $business,
            'outcome' => 'Reward claimed',
            'confirmedAt' => $reward->claimed_at,
            'note' => 'Keep this screen as your receipt.',
        ])

        {{-- ID-05: the same shared ask block as the redeem
             receipt, under the same cooldown clock. --}}
        @if ($reviewAsk ?? false)
            @include('flow._review-ask')
        @endif
    @elseif ($state === 'waiting')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <p class="mt-3"><x-state-pill state="on_its_way" /></p>
        <h1 class="type-display-xs mt-3">Your reward is on its way</h1>
        <p class="mt-2 text-text-2">Your reward: {{ $reward->reward_text_snapshot }}.</p>

        {{-- R4-U6 (Surface 2 borrow): the vesting facts in one
             well, shown together instead of as alternatives. The
             date row (REF-CLAIM-06) renders only inside the
             referrer's own session (G-17, controller-decided);
             the text promise (REF-CLAIM-09) always renders. --}}
        <div class="well mt-4">
            @if ($showAvailableDate)
                <p class="flex items-baseline justify-between gap-3">
                    <span class="type-caption text-text-2">Available</span>
                    <span class="font-medium text-text-1">{{ $business->localTime($reward->available_at)->format('l, F j, Y') }}</span>
                </p>
                <p class="mt-2 text-text-2">We will text you when it is ready, unless you have opted out of texts. You can also check this page.</p>
            @else
                {{-- F-5: the bare link hides the exact date
                     (G-17) but never the window: the remaining
                     vesting time, from the reward's own
                     timestamp, sits beside the text promise. --}}
                @if ($vestingWindow = \App\Support\RewardStatus::vestingWindow($reward))
                    <p class="text-text-2">{{ $vestingWindow }}</p>
                @endif
                <p class="text-text-2">We will text you when it is ready, unless you have opted out of texts. You can also check this page.</p>
            @endif
        </div>
    @elseif ($state === 'held')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <p class="mt-3"><x-state-pill state="in_review" /></p>
        <h1 class="type-display-xs mt-3">Held for review</h1>
        <p class="mt-2 text-text-2">Your reward: {{ $reward->reward_text_snapshot }}.</p>
        <p class="mt-2 text-text-2">Your reward is being confirmed by {{ $business->name }}.</p>
        <p class="mt-2 text-text-2">Nothing for you to do. We will text you when it is ready, unless you have opted out of texts. You can also check this page.</p>
    @elseif ($state === 'denied')
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <p class="mt-3"><x-state-pill state="not_approved" /></p>
        <h1 class="type-display-xs mt-3">This reward was not approved by {{ $business->name }}.</h1>
        <p class="mt-2 text-text-2">Your reward: {{ $reward->reward_text_snapshot }}.</p>
        <p class="mt-2 text-text-2">If you think this is a mistake, contact the store.</p>
    @elseif ($state === 'expired')
        {{-- M-4 [M4-CLAIM-EXP]: the named time-expired state.
             Closure-expired never reaches this template (the
             controller returns the generic page), so this branch
             is the time clock's alone. --}}
        <p class="text-sm text-text-2">{{ $business->name }}</p>
        <p class="mt-3"><x-state-pill state="expired" /></p>
        <h1 class="type-display-xs mt-3">This reward ran out on {{ $business->localTime($reward->expires_at)->format('F j, Y') }}.</h1>
        <p class="mt-2 text-text-2">Your reward: {{ $reward->reward_text_snapshot }}.</p>
        <p class="mt-2 text-text-2">If you think this is a mistake, contact the store.</p>
    @else
        <h1 class="type-display-xs">This link is not available</h1>
        <p class="mt-2 text-text-2">This link could not be used. Check the message we sent you for your latest link.</p>
    @endif
@endsection
