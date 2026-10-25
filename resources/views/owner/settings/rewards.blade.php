@extends('layouts.app')

@section('title', 'Rewards')

@section('notices_summary_only', '1')

@section('content')
    <h1 class="type-title-2 text-text-1">Rewards</h1>

    <nav aria-label="Breadcrumb" class="type-caption mt-2 text-text-2">
        <a class="text-text-2 hover:text-text-1" href="{{ route('settings') }}">Settings</a>
        <span aria-hidden="true"> / </span>
        <span aria-current="page" class="text-text-1">Rewards</span>
    </nav>

    <div class="mt-6 lg:flex lg:items-start lg:gap-8">
        <div class="lg:w-[220px] lg:flex-none">
            @include('owner.settings.nav')
        </div>
        <div class="mt-6 min-w-0 flex-1 lg:mt-0">

    @if ($packs !== [])
        <div class="panel mb-4">
            <h2 class="type-title-3 text-text-1">Start from a pack owners like you use</h2>
            <p class="mt-1 text-text-2">You can edit both rewards after picking.</p>
            <div class="mt-3 space-y-3">
                @foreach ($packs as $pack)
                    <div class="rounded-md border border-line p-4">
                        <p><span class="font-semibold">For the friend:</span> {{ $pack['friend'] }}</p>
                        <p class="mt-1"><span class="font-semibold">For the referrer:</span> {{ $pack['referrer'] }}</p>
                        <p class="mt-1 text-text-2">Typical cost: {{ $pack['cost'] }}</p>
                        <p class="mt-2">
                            <a class="btn btn-secondary" href="{{ route('settings.rewards', ['pack_friend' => $pack['friend'], 'pack_referrer' => $pack['referrer']]) }}">Use this pack</a>
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('settings.rewards.update') }}" class="panel [&_input]:max-w-[560px] [&_select]:max-w-[560px] [&_textarea]:max-w-[560px]" data-loading>
        @csrf

        <label for="friend_reward_text">Friend welcome reward</label>
        <input id="friend_reward_text" name="friend_reward_text" type="text" value="{{ old('friend_reward_text', $prefill['friend'] ?? $business->friend_reward_text) }}" required
            aria-invalid="{{ $errors->has('friend_reward_text') ? 'true' : 'false' }}"
            @if ($errors->has('friend_reward_text')) aria-describedby="friend_reward_text-error" @endif>
        @error('friend_reward_text') <p class="form-error" id="friend_reward_text-error">{{ $message }}</p> @enderror

        <label for="referrer_reward_text">Referrer reward</label>
        <input id="referrer_reward_text" name="referrer_reward_text" type="text" value="{{ old('referrer_reward_text', $prefill['referrer'] ?? $business->referrer_reward_text) }}"
            aria-invalid="{{ $errors->has('referrer_reward_text') ? 'true' : 'false' }}"
            @if ($errors->has('referrer_reward_text')) aria-describedby="referrer_reward_text-error" @endif>
        @error('referrer_reward_text') <p class="form-error" id="referrer_reward_text-error">{{ $message }}</p> @enderror
        <p class="form-help">Leave the referrer reward blank to use the friend reward.</p>

        <label for="redemption_window_days">Referral redemption window (days)</label>
        <input id="redemption_window_days" name="redemption_window_days" type="number" min="1" max="365" value="{{ old('redemption_window_days', $business->redemption_window_days) }}" required
            aria-invalid="{{ $errors->has('redemption_window_days') ? 'true' : 'false' }}"
            @if ($errors->has('redemption_window_days')) aria-describedby="redemption_window_days-error" @endif>
        @error('redemption_window_days') <p class="form-error" id="redemption_window_days-error">{{ $message }}</p> @enderror
        <p class="form-help">How many days a friend has to visit before the referral expires.</p>

        <label for="reward_vesting_days">Referrer reward wait (days)</label>
        <input id="reward_vesting_days" name="reward_vesting_days" type="number" min="{{ $vestingMin }}" max="{{ $vestingMax }}" value="{{ old('reward_vesting_days', $business->reward_vesting_days) }}" required
            aria-invalid="{{ $errors->has('reward_vesting_days') ? 'true' : 'false' }}"
            @if ($errors->has('reward_vesting_days')) aria-describedby="reward_vesting_days-error" @endif>
        @error('reward_vesting_days') <p class="form-error" id="reward_vesting_days-error">{{ $message }}</p> @enderror
        <p class="form-help">How many days a customer waits before a reward they earned by referring a friend is ready to use. A longer wait gives you time to undo a visit if a payment is refunded. Applies to new rewards only.</p>

        <p class="mt-4"><button type="submit" class="btn btn-primary">Save changes</button></p>
    </form>
        </div>
    </div>
@endsection
