@extends('layouts.app')

@section('title', 'Referral limits')


@section('content')
    <h1 class="type-title-2 text-text-1">Referral limits</h1>

    <p class="type-body mt-2 max-w-[68ch] text-text-2">How many rewards one customer can earn, so one person cannot run away with your program.</p>

    <div class="mt-6 lg:flex lg:items-start lg:gap-8">
        <div class="lg:w-[220px] lg:flex-none">
            @include('owner.settings.nav')
        </div>
        <div class="mt-6 min-w-0 flex-1 lg:mt-0">

    <form method="POST" action="{{ route('settings.limits.update') }}" class="panel [&_input]:max-w-[560px] [&_select]:max-w-[560px] [&_textarea]:max-w-[560px]" data-loading
        x-data="{
            daily: @js((string) old('daily_referral_cap', $business->daily_referral_cap)),
            monthly: @js((string) old('monthly_referral_cap', $business->monthly_referral_cap)),
        }">
        @csrf

        <p class="form-help">Limits keep one person from earning more than this from your store. Extra referrals are saved and held for your review.</p>

        <label for="daily_referral_cap">Rewards one customer can earn per day</label>
        <input id="daily_referral_cap" name="daily_referral_cap" type="number" min="{{ $dailyMin }}" max="{{ $dailyMax }}"
            value="{{ old('daily_referral_cap', $business->daily_referral_cap) }}" required x-model="daily"
            aria-invalid="{{ $errors->has('daily_referral_cap') ? 'true' : 'false' }}"
            @if ($errors->has('daily_referral_cap')) aria-describedby="daily_referral_cap-error daily_referral_cap-help" @else aria-describedby="daily_referral_cap-help" @endif>
        @error('daily_referral_cap') <p class="form-error" id="daily_referral_cap-error">{{ $message }}</p> @enderror
        {{-- SL-1: the range in words, from the same config source
             the controller validates against (passed in above). --}}
        <p class="form-help" id="daily_referral_cap-help">Between {{ $dailyMin }} and {{ $dailyMax }}. The starting setting is {{ $dailyDefault }}.</p>

        <label for="monthly_referral_cap">Rewards one customer can earn per month</label>
        <input id="monthly_referral_cap" name="monthly_referral_cap" type="number" min="{{ $monthlyMin }}" max="{{ $monthlyMax }}"
            value="{{ old('monthly_referral_cap', $business->monthly_referral_cap) }}" required x-model="monthly"
            aria-invalid="{{ $errors->has('monthly_referral_cap') ? 'true' : 'false' }}"
            @if ($errors->has('monthly_referral_cap')) aria-describedby="monthly_referral_cap-error monthly_referral_cap-help" @else aria-describedby="monthly_referral_cap-help" @endif>
        @error('monthly_referral_cap') <p class="form-error" id="monthly_referral_cap-error">{{ $message }}</p> @enderror
        <p class="form-help" id="monthly_referral_cap-help">Between {{ $monthlyMin }} and {{ $monthlyMax }}. The starting setting is {{ $monthlyDefault }}.</p>

        {{-- SL-2: the worked example (R2 Value pattern). The server
             render carries the saved values inside the live spans;
             Alpine keeps the figures bound to the fields on input. --}}
        <p class="type-body-sm mt-4 text-text-1" data-limits-example>At <span x-text="daily">{{ old('daily_referral_cap', $business->daily_referral_cap) }}</span> a day and <span x-text="monthly">{{ old('monthly_referral_cap', $business->monthly_referral_cap) }}</span> a month, one customer can earn up to <span x-text="monthly">{{ old('monthly_referral_cap', $business->monthly_referral_cap) }}</span> rewards a month. Referrals past the limit are saved and held for your review.</p>

        <p class="type-body-sm mt-2 text-text-2">Held referrals wait in the <a class="font-semibold text-link hover:underline" href="{{ route('reviews') }}">review queue</a>.</p>

        <p class="mt-4"><button type="submit" class="btn btn-primary">Save changes</button></p>
    </form>
        </div>
    </div>
@endsection
