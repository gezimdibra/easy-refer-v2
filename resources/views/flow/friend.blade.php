@extends('layouts.flow')

@section('title', 'Claim your reward | Easy Refer')
@section('flow_label', 'Claim')

@section('content')
    {{-- R5/R6, the friend landing and claim form (spec Section 3
         Steps 6 to 8, inventory REF-FRIEND and REF-CLAIM): the
         heading is REF-FRIEND-01; the referrer's phone appears
         nowhere on this page, in markup or script (REF-16).
         Errors follow the same pattern as R1: one summary line,
         each problem once at its field. --}}
    <h1 class="text-[32px] font-bold leading-[38px]">Invited by {{ $referrer->first_name }}</h1>

    @if ($business->friend_reward_text)
        <p class="mt-2 text-text-2">Claim {{ $business->friend_reward_text }} at {{ $business->name }}.</p>
    @else
        <p class="mt-2 text-text-2">Claim your reward at {{ $business->name }}.</p>
    @endif

    @include('partials.notices', ['summaryOnly' => true])

    <form method="POST" action="{{ route('refer.friend.claim', ['token' => $token]) }}" class="mt-5" id="claim-form">
        @csrf
        <input type="hidden" name="device_signal" data-device-signal>

        <div class="field">
            <label class="form-label" for="first_name">First name</label>
            <input class="form-input form-input-flow" id="first_name" name="first_name" type="text" autocomplete="given-name" required value="{{ old('first_name') }}"
                aria-invalid="{{ $errors->has('first_name') ? 'true' : 'false' }}"
                @if ($errors->has('first_name')) aria-describedby="first_name-error" @endif>
            @error('first_name') <p class="form-error" id="first_name-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="form-label" for="last_name">Last name</label>
            <input class="form-input form-input-flow" id="last_name" name="last_name" type="text" autocomplete="family-name" required value="{{ old('last_name') }}"
                aria-invalid="{{ $errors->has('last_name') ? 'true' : 'false' }}"
                @if ($errors->has('last_name')) aria-describedby="last_name-error" @endif>
            @error('last_name') <p class="form-error" id="last_name-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="form-label" for="phone">Mobile phone</label>
            <input class="form-input form-input-flow" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required value="{{ old('phone') }}"
                aria-invalid="{{ $errors->has('phone') ? 'true' : 'false' }}"
                @if ($phoneHelp ?? null) aria-describedby="phone-help @error('phone') phone-error @enderror" @elseif ($errors->has('phone')) aria-describedby="phone-error" @endif>
            @if ($phoneHelp ?? null)
                <p class="form-help" id="phone-help">{{ $phoneHelp }}</p>
            @endif
            @error('phone') <p class="form-error" id="phone-error">{{ $message }}</p> @enderror
        </div>

        @if (config('referrals.customer_email_field'))
            <div class="field">
                <label class="form-label" for="email">Email (optional)</label>
                <input class="form-input form-input-flow" id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}"
                    aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                    @if ($errors->has('email')) aria-describedby="email-error" @endif>
                @error('email') <p class="form-error" id="email-error">{{ $message }}</p> @enderror
            </div>
        @endif

        <label class="mt-4 flex items-start gap-3 text-base">
            <input type="checkbox" name="consent" value="1" class="mt-1 h-5 w-5" @checked(old('consent'))>
            <span>I agree to receive text messages about my rewards from {{ $business->name }}. Message and data rates may apply. Reply STOP to opt out.</span>
        </label>

        <label class="mt-3 flex items-start gap-3 text-base">
            <input type="checkbox" name="send_link" value="1" class="mt-1 h-5 w-5" @checked(old('send_link'))>
            <span>Text me my reward link so I can find it at the store.</span>
        </label>

        @error('turnstile') <p class="form-error mt-3">{{ $message }}</p> @enderror
        @include('partials.turnstile')
    </form>
@endsection

@section('action')
    <button type="submit" form="claim-form" class="btn btn-flow btn-primary w-full">Claim my reward</button>
@endsection
