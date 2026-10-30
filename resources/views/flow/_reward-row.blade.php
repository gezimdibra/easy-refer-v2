{{--
    SA-02 (Train B, Amendment 39): one additional reward on the
    share page, Variant B's compact row: the state's pill, the
    snapshot, the state's one truth line, and the state's own
    action to that reward's own claim page. Classification is
    the top block's and the claim page's exact precedence (held
    first, then ready, then waiting), and every sentence is the
    top block's pinned string for that state, so a row can never
    borrow a neighbour's state, date, or link. A held row never
    renders a date or a Use action; a ready row never renders
    the text promise (it is already ready).
    Expects: $reward (Reward), $claimUrl (?string), $business.
--}}
@php($rowState = $reward->hold ? 'held' : ($reward->state === \App\Models\Reward::STATE_AVAILABLE ? 'ready' : 'waiting'))
<div class="mt-4 border-t border-line pt-4">
    @if ($rowState === 'ready')
        <p><x-state-pill state="ready" /></p>
        <p class="mt-2 text-text-2">Your reward: {{ $reward->reward_text_snapshot }}.</p>
        {{-- M-4 [M4-SHARE-01]: the use-by date, from this row's own expires_at. --}}
        @if ($reward->expires_at)
            <p class="mt-2 text-text-2">Good until {{ $business->localTime($reward->expires_at)->format('F j, Y') }}.</p>
        @endif
        @if ($claimUrl)
            <a class="btn btn-secondary w-full mt-3" href="{{ $claimUrl }}">Use my reward</a>
        @endif
    @elseif ($rowState === 'held')
        <p><x-state-pill state="in_review" /></p>
        <p class="mt-2 text-text-2">Your reward: {{ $reward->reward_text_snapshot }}.</p>
        <p class="mt-2 text-text-2">Your reward is being confirmed by {{ $business->name }}.</p>
        <p class="mt-2 text-text-2">Nothing for you to do. We will text you when it is ready, unless you have opted out of texts. You can also check this page.</p>
        @if ($claimUrl)
            <p class="mt-3"><a href="{{ $claimUrl }}">See your reward</a></p>
        @endif
    @else
        <p><x-state-pill state="on_its_way" /></p>
        <p class="mt-2 text-text-2">Your reward: {{ $reward->reward_text_snapshot }}.</p>
        @if ($reward->available_at)
            <p class="mt-2 text-text-2">Your reward will be available on {{ $business->localTime($reward->available_at)->format('l, F j, Y') }}.</p>
        @elseif ($vestingWindow = \App\Support\RewardStatus::vestingWindow($reward))
            {{-- F-5: no stored date — the window still renders, never a bare waiting state. --}}
            <p class="mt-2 text-text-2">{{ $vestingWindow }}</p>
        @endif
        <p class="mt-2 text-text-2">We will text you when it is ready, unless you have opted out of texts. You can also check this page.</p>
        @if ($claimUrl)
            <p class="mt-3"><a href="{{ $claimUrl }}">See your reward</a></p>
        @endif
    @endif
</div>
