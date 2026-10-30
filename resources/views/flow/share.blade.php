@extends('layouts.flow')

@section('title', 'Share with a friend | Easy Refer')
@section('flow_label', 'Share')

@section('step_number', '3')
@section('step_total', '3')
@section('step')
    <span class="type-caption text-text-2">Step 3 of 3</span>
@endsection

@section('content')
    {{-- R4, the share page (spec Section 3 Step 5, design 3.5,
         inventory REF-SHARE): the designed greeting, the
         referrer's own reward line, one Share your link action
         (the system share sheet where the browser offers one,
         the clipboard everywhere else), the copyable link, the
         in-person QR, and the honest referral count. No channel
         grid: the share sheet owns destinations. --}}
    <h1 class="type-display-xs">
        @if ($returning)
            Welcome back, {{ $customer->first_name }}
        @else
            You are in, {{ $customer->first_name }}
        @endif
    </h1>

    @if ($friendToReferrerReminder ?? null)
        {{-- ID-08 [F2R-02]: the passive contagion reminder, one
             line directly above the prospective line, in its own
             styling. It names no reward (the prospective line
             below owns the reward truth) and no deadline, and it
             renders only while the controller's conjunctive gate
             holds; every other member's page is unchanged. --}}
        <p class="mt-2 text-text-2">You found {{ $business->name }} through {{ $friendToReferrerReminder['referrerFirstName'] }}, the same way your friends can find it through you.</p>
    @endif

    @if ($referrerRewardText)
        <p class="mt-2 text-text-2">When a friend visits {{ $business->name }} with your link, you get {{ $referrerRewardText }}</p>
    @endif

    @if ($topReward)
        {{-- SIM-1 residual (R3 Screen 3, Pick A): the earned-reward
             block, directly under the prospective line and above
             the share actions, so the state the member earned is
             the first thing this page shows after the greeting.
             Lines reuse the claim page's pinned strings verbatim;
             the date and the text promise render together (SIM-3
             parity). Share keeps the page's one primary; the
             reward's action is secondary weight. --}}
        @php($rewardState = $topReward->hold ? 'held' : ($topReward->state === \App\Models\Reward::STATE_AVAILABLE ? 'ready' : 'waiting'))
        <div class="well mt-5">
            @if ($rewardState === 'ready')
                <p><x-state-pill state="ready" /></p>
                <h2 class="type-title-3 mt-3">Your reward is ready</h2>
                <p class="mt-2 text-text-2">Your reward: {{ $topReward->reward_text_snapshot }}.</p>
                {{-- M-4 [M4-SHARE-01]: the use-by date, ready form only. --}}
                @if ($topReward->expires_at)
                    <p class="mt-2 text-text-2">Good until {{ $business->localTime($topReward->expires_at)->format('F j, Y') }}.</p>
                @endif
                @if ($rewardClaimUrl)
                    <a class="btn btn-secondary w-full mt-4" href="{{ $rewardClaimUrl }}">Use my reward</a>
                @endif
            @elseif ($rewardState === 'held')
                <p><x-state-pill state="in_review" /></p>
                <h2 class="type-title-3 mt-3">Held for review</h2>
                <p class="mt-2 text-text-2">Your reward: {{ $topReward->reward_text_snapshot }}.</p>
                <p class="mt-2 text-text-2">Your reward is being confirmed by {{ $business->name }}.</p>
                <p class="mt-2 text-text-2">Nothing for you to do. We will text you when it is ready, unless you have opted out of texts. You can also check this page.</p>
                @if ($rewardClaimUrl)
                    <p class="mt-3"><a href="{{ $rewardClaimUrl }}">See your reward</a></p>
                @endif
            @else
                <p><x-state-pill state="on_its_way" /></p>
                <h2 class="type-title-3 mt-3">Your reward is on its way</h2>
                <p class="mt-2 text-text-2">Your reward: {{ $topReward->reward_text_snapshot }}.</p>
                @if ($topReward->available_at)
                    <p class="mt-2 text-text-2">Your reward will be available on {{ $business->localTime($topReward->available_at)->format('l, F j, Y') }}.</p>
                @elseif ($vestingWindow = \App\Support\RewardStatus::vestingWindow($topReward))
                    {{-- F-5: no stored date — the window still renders, never a bare waiting state. --}}
                    <p class="mt-2 text-text-2">{{ $vestingWindow }}</p>
                @endif
                <p class="mt-2 text-text-2">We will text you when it is ready, unless you have opted out of texts. You can also check this page.</p>
                @if ($rewardClaimUrl)
                    <p class="mt-3"><a href="{{ $rewardClaimUrl }}">See your reward</a></p>
                @endif
            @endif

        </div>
    @endif

    @if (! empty($additionalRewards))
        {{-- SA-02 (Train B, Amendment 39): every other live reward,
             as its own row in its own state, under a label that is
             not a count. The old count sentence ("You have N more
             rewards.") is deleted: it presented held and waiting
             rewards as an undifferentiated "have" and gave none of
             them a path. The list itself is the count. --}}
        <div class="well mt-5">
            <h2 class="type-title-3">More rewards</h2>
            @foreach ($additionalRewards as $additional)
                @include('flow._reward-row', ['reward' => $additional['reward'], 'claimUrl' => $additional['claimUrl']])
            @endforeach
        </div>
    @endif

    @include('flow._share-actions')

    <div class="well mt-5 text-center">
        <img src="{{ $shareQrUrl }}" alt="Your personal referral QR code" width="200" height="200" class="mx-auto rounded-lg border border-line bg-surface-0 p-3">
        <p class="type-body-sm mt-3 text-text-2">Show this to a friend in person</p>
    </div>

    @if ($referralCount > 0)
        <p class="status status-success mt-4" role="status">You have referred {{ $referralCount }} {{ $referralCount === 1 ? 'friend' : 'friends' }} so far.</p>
    @else
        <p class="mt-4 text-text-2">You have not referred anyone yet. Your first share could be today.</p>
    @endif

    <p class="mt-4 text-text-2">Your link stays the same. Bookmark this page to find it again.</p>
@endsection
