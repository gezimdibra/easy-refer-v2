@extends('layouts.flow')

@section('title', 'Claim your reward | Easy Refer')
@section('flow_label', 'Claim')

{{-- RC-3 (VOL-3): the friend path has 2 steps — this claim form
     and the code screen — and the visit page after them is the
     completion. It is labelled 1 of 2 so the code screen's
     "Step 2 of 2" has the Step 1 it names. --}}
@section('step_number', '1')
@section('step_total', '2')
@section('step')
    <span class="type-caption text-text-2">Step 1 of 2</span>
@endsection

@if ($og ?? null)
    {{-- RS-N1 (Train B, Amendment 39): the share preview for
         this link, pushed only when the controller built the
         payload (live business). No date or deadline anywhere:
         a share link has none. --}}
    @push('og_tags')
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ $og['title'] }}">
        <meta property="og:description" content="{{ $og['description'] }}">
        <meta property="og:image" content="{{ $og['image'] }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="{{ $og['alt'] }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $og['title'] }}">
        <meta name="twitter:description" content="{{ $og['description'] }}">
        <meta name="twitter:image" content="{{ $og['image'] }}">
    @endpush
@endif

@section('content')
    {{-- R5/R6, the friend landing and claim form (spec Section 3
         Steps 6 to 8, inventory REF-FRIEND and REF-CLAIM): the
         heading is REF-FRIEND-01; the referrer's phone appears
         nowhere on this page, in markup or script (REF-16).
         Errors follow the same pattern as R1: one summary line,
         each problem once at its field. --}}
    <h1 class="type-display-xs">Invited by {{ $referrer->first_name }}</h1>

    @if ($business->friend_reward_text)
        <p class="mt-2 text-text-2">Claim {{ $business->friend_reward_text }} at {{ $business->name }}.</p>
    @else
        <p class="mt-2 text-text-2">Claim your reward at {{ $business->name }}.</p>
    @endif

    @if ($business->rewardsAreSplit())
        {{-- SA-12 / RK-06 (owner ruling 2026-10-27; Amendment
             35): the friend also sees what the referrer gets,
             in the same plain register. Business::giveGetLine()
             is the poster's sentence, spoken to the referrer,
             so it does not compose this line; this page speaks
             to the friend. OR-1: this shipped line is the SPLIT
             branch, keyed on the flag, value from the resolver. --}}
        <p class="mt-2 text-text-2">{{ $referrer->first_name }} gets {{ $business->effectiveReferrerRewardText() }} when you visit.</p>
    @elseif ($business->rewardText() !== null)
        {{-- OR-1 / ORS-03 (Amendment 49): the UNIFIED branch —
             in unified the referrer's reward always exists (it
             is the same reward), so the both-line always
             carries. Never the other branch: the key is the
             flag, one method, both directions pinned. --}}
        <p class="mt-2 text-text-2">You both get {{ trim(rtrim(trim((string) $business->rewardText()), '.')) }} when you visit.</p>
    @endif

    @include('partials.notices')

    <form method="POST" action="{{ route('refer.friend.claim', ['token' => $token]) }}" class="mt-5" id="claim-form" data-loading>
        @csrf
        <input type="hidden" name="device_signal" data-device-signal>

        <div class="field">
            <label class="form-label" for="first_name">First name</label>
            <input class="form-input form-input-flow" id="first_name" name="first_name" type="text" autocomplete="given-name" required value="{{ old('first_name', $draft['first_name'] ?? '') }}"
                aria-invalid="{{ $errors->has('first_name') ? 'true' : 'false' }}"
                @if ($errors->has('first_name')) aria-describedby="first_name-error" @endif>
            @error('first_name') <p class="form-error" id="first_name-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="form-label" for="last_name">Last name (optional)</label>
            <input class="form-input form-input-flow" id="last_name" name="last_name" type="text" autocomplete="family-name" value="{{ old('last_name', $draft['last_name'] ?? '') }}"
                aria-invalid="{{ $errors->has('last_name') ? 'true' : 'false' }}"
                @if ($errors->has('last_name')) aria-describedby="last_name-error" @endif>
            @error('last_name') <p class="form-error" id="last_name-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="form-label" for="phone">Mobile phone</label>
            <input class="form-input form-input-flow" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required value="{{ old('phone', $draft['phone'] ?? '') }}"
                aria-invalid="{{ $errors->has('phone') ? 'true' : 'false' }}"
                @if ($phoneHelp ?? null) aria-describedby="phone-help @error('phone') phone-error @enderror" @elseif ($errors->has('phone')) aria-describedby="phone-error" @endif>
            @if ($phoneHelp ?? null)
                <p class="form-help" id="phone-help">{{ $phoneHelp }}</p>
            @endif
            @error('phone') <p class="form-error" id="phone-error">{{ $message }}</p> @enderror
        </div>

        @include('flow._no-catch-strip', ['frame' => 'friend'])

        @if (config('referrals.customer_email_field'))
            <div class="field">
                <label class="form-label" for="email">Email (optional)</label>
                <input class="form-input form-input-flow" id="email" name="email" type="email" autocomplete="email" value="{{ old('email', $draft['email'] ?? '') }}"
                    aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                    @if ($errors->has('email')) aria-describedby="email-error" @endif>
                @error('email') <p class="form-error" id="email-error">{{ $message }}</p> @enderror
            </div>
        @endif

        <label class="well mt-5 flex items-start gap-3 text-base">
            <input type="checkbox" id="consent" name="consent" value="1" class="mt-0.5 h-[22px] w-[22px] flex-none accent-action" @checked(old('consent', $draft['consent'] ?? false))
                aria-invalid="{{ $errors->has('consent') ? 'true' : 'false' }}"
                @if ($errors->has('consent')) aria-describedby="consent-error" @endif>
            <span>I agree to receive text messages about my rewards from {{ $business->name }}. Message and data rates may apply. Reply STOP to opt out.</span>
        </label>
        @error('consent') <p class="form-error mt-2" id="consent-error">{{ $message }}</p> @enderror

        <label class="mt-3 flex items-start gap-3 text-base">
            <input type="checkbox" name="send_link" value="1" class="mt-0.5 h-[22px] w-[22px] flex-none accent-action" @checked(old('send_link'))>
            <span>Text me my reward link so I can find it at the store.</span>
        </label>

        @error('turnstile') <p class="form-error mt-3">{{ $message }}</p> @enderror
        @include('partials.turnstile')
    </form>
@endsection

@section('action')
    <button type="submit" form="claim-form" class="btn btn-flow btn-primary w-full">Claim my reward</button>
@endsection
