@extends('layouts.flow')

@section('title', 'Share with a friend | Easy Refer')
@section('flow_label', 'Share')

@section('step')
    <span class="text-sm text-text-2">Step 3 of 3</span>
@endsection

@section('content')
    {{-- R4, the share page (spec Section 3 Step 5, design 3.5,
         inventory REF-SHARE): the designed greeting, the
         referrer's own reward line, one Share your link action
         (the system share sheet where the browser offers one,
         the clipboard everywhere else), the copyable link, the
         in-person QR, and the honest referral count. No channel
         grid: the share sheet owns destinations. --}}
    <h1 class="text-[28px] font-bold leading-tight">
        @if ($returning)
            Welcome back, {{ $customer->first_name }}
        @else
            You are in, {{ $customer->first_name }}
        @endif
    </h1>

    @if ($referrerRewardText)
        <p class="mt-2 text-text-2">When a friend visits {{ $business->name }} with your link, you get {{ $referrerRewardText }}</p>
    @endif

    <div x-data="{ copied: false }">
        <div class="mt-5">
            <button type="button" class="btn btn-primary btn-flow w-full"
                @click="if (navigator.share) { navigator.share({ title: {{ Js::from($business->name) }}, text: {{ Js::from($shareMessage) }}, url: {{ Js::from($shareUrl) }} }) } else { navigator.clipboard.writeText($refs.shareUrl.value); copied = true }">Share your link</button>
        </div>

        <div class="field mt-4">
            <label class="form-label" for="share_url">Your referral link</label>
            <div class="flex gap-2">
                <input class="form-input" id="share_url" type="text" readonly value="{{ $shareUrl }}" x-ref="shareUrl">
                <button type="button" class="btn btn-secondary"
                    @click="navigator.clipboard.writeText($refs.shareUrl.value); copied = true"
                    x-text="copied ? 'Copied' : 'Copy link'">Copy link</button>
            </div>
        </div>
    </div>

    <div class="mt-5 text-center">
        <img src="{{ $shareQrUrl }}" alt="Your personal referral QR code" width="200" height="200" class="mx-auto">
        <p class="mt-2 text-text-2">Show this to a friend in person</p>
    </div>

    @if ($referralCount > 0)
        <p class="status status-success mt-4" role="status">You have referred {{ $referralCount }} {{ $referralCount === 1 ? 'friend' : 'friends' }} so far.</p>
    @else
        <p class="mt-4 text-text-2">You have not referred anyone yet. Your first share could be today.</p>
    @endif

    <p class="mt-4 text-text-2">Your link stays the same. Bookmark this page to find it again.</p>
@endsection
