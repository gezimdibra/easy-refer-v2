@extends('layouts.app')

@section('title', 'Referral limits')

@section('notices_summary_only', '1')

@section('content')
    <h1 class="text-2xl font-bold">Referral limits</h1>

    @include('owner.settings.nav')

    <form method="POST" action="{{ route('settings.limits.update') }}" class="card">
        @csrf

        <p class="form-help">Limits keep one person from earning more than this from your store. Extra referrals are saved and held for your review.</p>

        <label for="daily_referral_cap">Rewards one customer can earn per day</label>
        <input id="daily_referral_cap" name="daily_referral_cap" type="number" min="{{ $dailyMin }}" max="{{ $dailyMax }}"
            value="{{ old('daily_referral_cap', $business->daily_referral_cap) }}" required
            aria-invalid="{{ $errors->has('daily_referral_cap') ? 'true' : 'false' }}"
            @if ($errors->has('daily_referral_cap')) aria-describedby="daily_referral_cap-error" @endif>
        @error('daily_referral_cap') <p class="form-error" id="daily_referral_cap-error">{{ $message }}</p> @enderror

        <label for="monthly_referral_cap">Rewards one customer can earn per month</label>
        <input id="monthly_referral_cap" name="monthly_referral_cap" type="number" min="{{ $monthlyMin }}" max="{{ $monthlyMax }}"
            value="{{ old('monthly_referral_cap', $business->monthly_referral_cap) }}" required
            aria-invalid="{{ $errors->has('monthly_referral_cap') ? 'true' : 'false' }}"
            @if ($errors->has('monthly_referral_cap')) aria-describedby="monthly_referral_cap-error" @endif>
        @error('monthly_referral_cap') <p class="form-error" id="monthly_referral_cap-error">{{ $message }}</p> @enderror

        <p class="mt-4"><button type="submit" class="btn btn-primary">Save changes</button></p>
    </form>
@endsection
