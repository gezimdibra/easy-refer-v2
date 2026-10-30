@extends('layouts.flow')

@section('title', 'Confirm redemption | Easy Refer')
@section('flow_label', 'Counter')

@section('content')
    {{-- R8a, the staff redemption confirmation (spec Section 6.1
         item 3, design 3.5). R4-U3 (Surface 4 pick C): the verdict
         band answers "can I press?" before a word is read; the
         reward is the headline; whose it is at counter size; the
         two verification facts (business, sender) as labelled rows
         in one well. No PIN, no account: whoever holds the phone
         confirms. --}}
    <x-state-band :state="$bandState ?? 'ready_to_redeem'" :detail="$bandDetail ?? null" />

    {{-- TWO-KEY FLAG (F-UP-1): reward-truth hunk. The confirm
         screen is the handover instruction, so its headline is
         the claim-time promise (the referral's snapshot, with
         its named LEGACY LIVE-CONFIG FALLBACK when null): the
         reward staff hand over is the one the friend claimed,
         even if the owner edited the config after the claim. --}}
    <h1 class="type-display-xs">{{ $referral->promisedFriendRewardText() ?? 'First visit reward' }}</h1>

    <p class="mt-3 font-medium text-text-1" style="font-size: 22px; line-height: 28px">For {{ $friend->first_name }}</p>

    <div class="well mt-4">
        <p class="flex items-baseline justify-between gap-3">
            <span class="type-caption text-text-2">Business</span>
            <span class="font-medium text-text-1">{{ $business->name }}</span>
        </p>
        <p class="mt-2 flex items-baseline justify-between gap-3">
            <span class="type-caption text-text-2">Sent by</span>
            <span class="font-medium text-text-1">{{ $referral->referrerMembership?->customer?->first_name ?? 'A customer' }}</span>
        </p>
    </div>

    @if (session('staff_error'))
        <p class="status status-error mt-3" role="status">{{ session('staff_error') }}</p>
    @elseif (($blockedMessage ?? null) !== null)
        {{-- Train F2 (Amendment 40, F2-RDM-01): the blocked
             state renders the same line in the error slot,
             before any press exists to answer. --}}
        <p class="status status-error mt-3" role="status">{{ $blockedMessage }}</p>
    @endif
@endsection

@section('action')
    @if (($gapBlocked ?? false) === true)
        {{-- [GAP-RDM-01] The gap-blocked action: retry the SAME
             token with a fresh GET of this screen. No form, no
             nonce, no override exists inside the window. --}}
        <a class="btn btn-counter btn-primary w-full" href="{{ route('redeem.show', ['token' => $token]) }}">Check again</a>
    @elseif (($blockedMessage ?? null) === null)
        <form method="POST" action="{{ route('redeem.confirm', ['token' => $token]) }}">
            @csrf
            <input type="hidden" name="nonce" value="{{ $nonce }}">
            <button type="submit" class="btn btn-counter btn-primary w-full">Mark as Redeemed</button>
        </form>
    @endif
    <p class="mt-3 text-center">
        <a class="font-semibold text-link hover:underline" href="{{ route('home') }}">Not now</a>
    </p>
@endsection
