@extends('layouts.app')

@section('title', 'Rewards')

@section('notices_summary_only', '1')

@section('content')
    <h1 class="text-[28px] font-bold">Rewards</h1>

    @include('owner.settings.nav')

    @if ($packs !== [])
        <div class="card mb-4">
            <h2 class="text-xl font-semibold">Start from a pack owners like you use</h2>
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

    <form method="POST" action="{{ route('settings.rewards.update') }}" class="card">
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

        <p class="mt-4"><button type="submit" class="btn btn-primary">Save changes</button></p>
    </form>
@endsection
