@extends('layouts.app')

@section('title', 'Rewards')


@section('content')
    <h1 class="type-title-2 text-text-1">Rewards</h1>

    <p class="type-body mt-2 max-w-[68ch] text-text-2">Your reward, and the timing.</p>

    <div class="mt-6 lg:flex lg:items-start lg:gap-8">
        <div class="lg:w-[220px] lg:flex-none">
            @include('owner.settings.nav')
        </div>
        <div class="mt-6 min-w-0 flex-1 lg:mt-0">

    @if ($packs !== [])
        @php
            $rewardsSet = filled($business->friend_reward_text);
        @endphp
        @if ($rewardsSet)
            {{-- R2 SET-REWARDS: once an offer is saved, the packs
                 collapse to a one-line strip that expands on tap.
                 OR-1: packs are single rewards now. --}}
            <details class="panel mb-4">
                <summary class="cursor-pointer font-semibold text-text-1">Starter packs · {{ count($packs) }} ready-made rewards, if you ever want a change</summary>
                <div class="mt-3 space-y-3">
                    @foreach ($packs as $pack)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-line px-4 py-3">
                            <p class="type-body-sm">{{ $pack['reward'] }}</p>
                            <a class="btn btn-secondary" href="{{ route('settings.rewards', ['pack_reward' => $pack['reward']]) }}">Use this pack</a>
                        </div>
                    @endforeach
                </div>
            </details>
        @else
            <div class="panel mb-4">
                <h2 class="type-title-3 text-text-1">Start from a ready-made pack</h2>
                <p class="mt-1 text-text-2">You can edit the reward after picking.</p>
                <div class="mt-3 space-y-3">
                    @foreach ($packs as $pack)
                        <div class="rounded-md border border-line p-4">
                            <p>{{ $pack['reward'] }}</p>
                            <p class="mt-1 text-text-2">Typical cost: {{ $pack['cost'] }}</p>
                            <p class="mt-2">
                                <a class="btn btn-secondary" href="{{ route('settings.rewards', ['pack_reward' => $pack['reward']]) }}">Use this pack</a>
                            </p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif

    @php
        // OR-1 §4: the form's starting configuration. A pack
        // prefill is a unified offer, so it turns the toggle OFF
        // in the form (§4.1 pin); otherwise the toggle reflects
        // the stored flag. The referrer field's start value
        // keys on that flag (C-3 note below): the stored
        // referrer text when split, the reward itself when
        // unified (the §4.2 prefill: "the same, until you
        // change it").
        $packPrefill = ($prefill['pack'] ?? false) ? ($prefill['friend'] ?? null) : null;
        $formReward = old('friend_reward_text', $packPrefill ?? $business->friend_reward_text);
        $formSplit = $packPrefill !== null ? false : (bool) old('reward_split', $business->rewardsAreSplit());
        // C-3 (two-key review): the referrer field's start value
        // keys on the STORED flag, not the raw column. Flag
        // false: the business is unified, so the field starts
        // as the current reward (its helper's promise), even
        // when an orphaned divergent referrer column survives
        // from a pre-OR-1 pair. Flag true: the stored referrer
        // text is the split value and starts the field.
        $formReferrer = old('referrer_reward_text', $business->rewardsAreSplit() ? ($business->referrer_reward_text ?: $formReward) : $formReward);
        $storedReferrer = trim((string) $business->referrer_reward_text);
        $storedReward = trim((string) $business->friend_reward_text);
        $divergent = $business->rewardsAreSplit() && $storedReferrer !== '' && $storedReferrer !== $storedReward;
    @endphp
    <form method="POST" action="{{ route('settings.rewards.update') }}" class="panel [&_input]:max-w-[560px] [&_select]:max-w-[560px] [&_textarea]:max-w-[560px]" data-loading
        x-data="{
            friend: @js($formReward),
            referrer: @js($formReferrer),
            split: @js($formSplit),
            storedReferrer: @js($storedReferrer),
            divergent: @js($divergent),
            windowDays: @js((string) old('redemption_window_days', $business->redemption_window_days)),
            vestingDays: @js((string) old('reward_vesting_days', $business->reward_vesting_days)),
        }">
        @csrf

        {{-- R2 SET-REWARDS (design section 10): group headings
             in the form's own vocabulary split. --}}
        <h2 class="type-title-3 text-text-1">The offer</h2>

        <label for="friend_reward_text">The reward</label>
        <input id="friend_reward_text" name="friend_reward_text" type="text" x-model="friend" value="{{ $formReward }}" required
            aria-invalid="{{ $errors->has('friend_reward_text') ? 'true' : 'false' }}"
            @if ($errors->has('friend_reward_text')) aria-describedby="friend_reward_text-error" @endif>
        @error('friend_reward_text') <p class="form-error" id="friend_reward_text-error">{{ $message }}</p> @enderror
        <p class="form-help">A new customer who comes in from a referral gets this. So does the customer who sent them — unless you give the referrer something different below.</p>

        {{-- OR-1 §4.1 (ORS-02): the split option, named by what
             it does in the owner's own words. It takes effect on
             Save, through the same save + audit path as the
             texts — never on click. --}}
        <div class="mt-5">
            <label class="flex items-start gap-3">
                <input type="checkbox" name="reward_split" value="1" x-model="split" class="mt-0.5 h-[20px] w-[20px] flex-none accent-action" @checked($formSplit)>
                <span class="font-semibold text-text-1">Give the referrer something different</span>
            </label>
            <p class="form-help" x-show="!split">Off: the referrer gets the same reward as the new customer. On: you choose a separate reward for the referrer.</p>
            <p class="form-help" x-show="split" x-cloak>Rewards already earned keep the words they were earned with.</p>
        </div>

        {{-- The referrer field exists only while the toggle is
             on in the form (§4.1: absent, not disabled — a
             disabled visible field would present a second
             reward the unified business does not have). It
             appears prefilled with the current reward (§4.2). --}}
        <div x-show="split" x-cloak>
            <label for="referrer_reward_text">Referrer reward</label>
            <input id="referrer_reward_text" name="referrer_reward_text" type="text" x-model="referrer" value="{{ $formReferrer }}"
                aria-invalid="{{ $errors->has('referrer_reward_text') ? 'true' : 'false' }}"
                @if ($errors->has('referrer_reward_text')) aria-describedby="referrer_reward_text-error" @endif>
            @error('referrer_reward_text') <p class="form-error" id="referrer_reward_text-error">{{ $message }}</p> @enderror
            <p class="form-help">Starts as the same reward. Change it to give the referrer something different.</p>
        </div>

        {{-- OR-1 §4.2: turning split OFF with a divergent stored
             referrer reward reveals this inline confirm BEFORE
             save. Its first quote is the STORED referrer value;
             its second quote binds the form's LIVE reward field
             (C-4, two-key review), because that live value is
             what the save will store, so an in-session edit can
             never make the quote stale. "Turn it off and save"
             submits the whole form unified. With equal values
             nothing is at stake and no confirm renders. --}}
        <div class="well-2 mt-5" x-show="divergent && !split" x-cloak id="unify-confirm">
            <p>Right now the referrer gets: '<span x-text="storedReferrer"></span>'. Turning this off means referrers get '<span x-text="friend"></span>' instead, starting with the next visit that counts. Rewards already earned keep the words they were earned with. The separate referrer reward is not kept after you save.</p>
            <p class="mt-3 flex flex-wrap gap-3">
                <button type="button" class="btn btn-secondary" x-on:click="split = true">Keep the separate reward</button>
                <button type="submit" class="btn btn-primary">Turn it off and save</button>
            </p>
        </div>

        <h2 class="type-title-3 mt-6 text-text-1">The timing</h2>

        <label for="redemption_window_days">Referral redemption window (days)</label>
        <input id="redemption_window_days" name="redemption_window_days" type="number" min="1" max="365" x-model="windowDays" value="{{ old('redemption_window_days', $business->redemption_window_days) }}" required
            aria-invalid="{{ $errors->has('redemption_window_days') ? 'true' : 'false' }}"
            @if ($errors->has('redemption_window_days')) aria-describedby="redemption_window_days-error" @endif>
        @error('redemption_window_days') <p class="form-error" id="redemption_window_days-error">{{ $message }}</p> @enderror
        <p class="form-help">How many days a friend has to visit before the referral expires.</p>

        <label for="reward_vesting_days">Referrer reward wait (days)</label>
        <input id="reward_vesting_days" name="reward_vesting_days" type="number" min="{{ $vestingMin }}" max="{{ $vestingMax }}" value="{{ old('reward_vesting_days', $business->reward_vesting_days) }}" x-model="vestingDays" required
            aria-invalid="{{ $errors->has('reward_vesting_days') ? 'true' : 'false' }}"
            @if ($errors->has('reward_vesting_days')) aria-describedby="reward_vesting_days-error" @endif>
        @error('reward_vesting_days') <p class="form-error" id="reward_vesting_days-error">{{ $message }}</p> @enderror
        <p class="form-help">How many days a customer waits before a reward they earned by referring a friend is ready to use. A longer wait gives you time to undo a visit if a payment is refunded. Applies to new rewards only.</p>

        {{-- The preview card: what customers see, bound to the
             live form state above. OR-1 §4.3: UNIFIED previews
             ONE reward line; SPLIT previews the two lines. The
             timing sentence is configuration-invariant and
             renders in both states. --}}
        <div class="well-2 mt-6">
            <h3 class="type-title-4 text-text-1">What customers see</h3>
            <p class="mt-2"><span class="font-semibold">What a new customer gets:</span> <span x-text="friend"></span></p>
            <p class="mt-1" x-show="split" x-cloak><span class="font-semibold">For the referrer:</span> <span x-text="referrer || friend"></span></p>
            <p class="type-body-sm mt-2 text-text-2">Friends have <span x-text="windowDays"></span> days to visit. The friend's reward is ready at the visit. The referrer's reward is ready <span x-text="vestingDays"></span> days after the visit. The referrer's reward stays good for {{ (int) config('referrals.reward_expiry_months', 12) }} months after it is ready.</p>
        </div>

        <p class="mt-4"><button type="submit" class="btn btn-primary">Save changes</button></p>
    </form>
        </div>
    </div>
@endsection
