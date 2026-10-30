@extends('layouts.onboarding')

@section('title', 'Set your reward')

@section('content')
    @include('owner.onboarding._progress')

    {{-- OB-2 Rewards, ONE-REWARD form (OR-1 / Amendment 49,
         design pass §3.2, copy family ORS-01): ONE question, ONE
         field prefilled with the starter answer for the
         business's type, ONE primary action. The owner approves
         rather than authors. The save goes through the one
         reward write path (RewardTextController, route
         owner.reward-texts), which always stores UNIFIED.
         Splitting lives only in Settings. No two-sided framing
         appears on this step (§3.4 grep scope). --}}
    @php
        $starter = $packs[0];
        $presentedReward = old('friend_reward_text', $business->friend_reward_text ?: $starter['reward']);
    @endphp
    <h1 class="type-title-2 text-text-1">What do you want to give a new customer who comes in from a referral?</h1>
    @if ($business->claim_source === 'claimable_page' && $business->friend_reward_text)
        {{-- G1-C29 (claim path only): the demo's starter reward,
             preselected at the checkpoint; both sides hold it
             (one-reward), so this line is true as rendered. --}}
        <p class="type-body mt-2 text-text-2">{{ \App\Support\ClaimableCopy::c29For($business->friend_reward_text) }}</p>
    @endif

    @if ($done)
        <div class="panel mt-6">
            <p class="text-text-1"><span class="font-semibold">✓ Reward set.</span></p>
            <p class="mt-2 text-text-1">{{ $business->friend_reward_text }}</p>
            <p class="mt-2 text-text-2">You can change it any time in Settings.</p>
            <p class="mt-4"><a class="btn btn-primary" href="{{ route('onboarding.step', $next) }}">Continue</a></p>
        </div>
    @endif

    <form method="POST" action="{{ route('owner.reward-texts') }}" class="panel mt-6" data-loading id="reward-step-form">
        @csrf
        <input type="hidden" name="advance" value="1">
        <input type="hidden" name="presented_reward_text" value="{{ $presentedReward }}">
        <label for="friend_reward_text">Referral Reward</label>
        <input id="friend_reward_text" name="friend_reward_text" type="text" value="{{ $presentedReward }}" required
            aria-invalid="{{ $errors->has('friend_reward_text') ? 'true' : 'false' }}"
            @if ($errors->has('friend_reward_text')) aria-describedby="friend_reward_text-error" @endif>
        @error('friend_reward_text') <p class="form-error" id="friend_reward_text-error">{{ $message }}</p> @enderror
        <p class="form-help">The customer who sent them gets the same reward.</p>
        {{-- M-4 [M4-SET-01 twin]: the term is stated before the
             first reward is ever minted, not only in Settings
             after the fact. --}}
        <p class="form-help">The referrer's reward stays good for {{ (int) config('referrals.reward_expiry_months', 12) }} months after it is ready.</p>
        <p class="form-help" id="reward-cost-line">Typical cost: {{ $starter['cost'] }}</p>

        <p class="mt-4"><button type="submit" class="btn btn-primary">Use this reward</button></p>
    </form>

    {{-- "See other ideas" (§3.2 F): the other packs for the
         type, as single-reward cards. Tapping one fills the
         field and the cost line; it does NOT submit, so an
         accidental tap never silently replaces a typed reward. --}}
    <details class="mt-6">
        <summary class="cursor-pointer font-semibold text-text-1">See other ideas</summary>
        <div class="mt-3 space-y-3">
            @foreach (array_slice($packs, 1) as $pack)
                <div class="panel">
                    <p class="type-body text-text-1">{{ $pack['reward'] }}</p>
                    <p class="type-body mt-1 text-text-2"><span class="font-semibold text-text-1">Typical cost:</span> {{ $pack['cost'] }}</p>
                    <p class="mt-2"><button type="button" class="btn btn-secondary" data-use-idea="{{ $pack['reward'] }}">Use this one</button></p>
                </div>
            @endforeach
        </div>
    </details>

    <script>
        (function () {
            var packs = @json($packs);
            var genericCost = 'You provide this reward when a new customer visits. It costs you only when someone comes in.';
            var field = document.getElementById('friend_reward_text');
            var line = document.getElementById('reward-cost-line');
            if (!field || !line) { return; }
            function syncCost() {
                var value = field.value.trim();
                for (var i = 0; i < packs.length; i++) {
                    if (packs[i].reward === value) {
                        line.textContent = 'Typical cost: ' + packs[i].cost;
                        return;
                    }
                }
                line.textContent = genericCost;
            }
            field.addEventListener('input', syncCost);
            syncCost();
            document.querySelectorAll('[data-use-idea]').forEach(function (button) {
                button.addEventListener('click', function () {
                    field.value = button.getAttribute('data-use-idea');
                    syncCost();
                    field.focus();
                });
            });
        })();
    </script>

    <p class="mt-6">
        <a class="text-action" href="{{ route('onboarding.step', $next) }}">Skip this step</a>
    </p>
    {{-- Amendment 40: the skip line is valid only while the reward text is blank; on a texts-set revisit it would assert a falsehood, so it renders only in the blank state (two-key condition 3). --}}
    @unless ($business->hasFriendRewardText())
        <p class="type-body-sm mt-2 text-text-2">Skipping leaves your code off until you set a reward: customers cannot join yet.</p>
    @endunless
@endsection
