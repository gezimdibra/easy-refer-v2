@extends('layouts.app')

@section('title', 'Referral detail')

@section('notices_summary_only', '1')
@section('notices_banner_keys', 'referral')

@section('content')
    {{-- Referral detail (spec Section 5.3): the timeline is the
         referral_events ledger for this referral, in order, with
         the inventory's event labels (REFL-06). --}}
    <nav aria-label="Breadcrumb" class="type-caption flex min-h-12 items-center gap-1 text-text-3">
        <a class="text-text-2 hover:text-text-1" href="{{ route('referrals.index') }}">Back to referrals</a>
        <span aria-hidden="true"> / </span>
        <span aria-current="page" class="text-text-1">Referral detail</span>
    </nav>

    <div class="panel mt-4">
        <h1 class="type-title-2 text-text-1">Referral detail</h1>
        <p class="mt-1 text-text-2">{{ $referral->referredCustomer->first_name }}, referred by {{ $referral->referrerMembership->customer->first_name }} · Started {{ $referral->created_at->format('M j, Y') }}</p>
        <p class="mt-2">
            <span class="pill {{ \App\Support\ReferralStatus::present($referral)['pill'] }}">{{ \App\Support\ReferralStatus::present($referral)['label'] }}</span>
            @if ($referral->reward !== null)
                <span class="pill {{ \App\Support\RewardStatus::present($referral->reward)['pill'] }}">{{ \App\Support\RewardStatus::present($referral->reward)['label'] }}</span>
            @endif
        </p>
        @if ($referral->hold && $holdReasons !== [])
            <p class="mt-2 text-text-2">Held for review: {{ implode(' ', $holdReasons) }} <a class="inline-flex min-h-12 items-center font-semibold text-brand-700 hover:underline" href="{{ route('reviews') }}">Open the review queue</a></p>
        @endif

        @if ($redeemUrl !== null)
            <div class="mt-4">
                <label class="form-label" for="redeem-link">Redeem link</label>
                <div class="joined max-w-[560px]">
                    <input class="form-input" id="redeem-link" type="text" readonly value="{{ $redeemUrl }}" onclick="this.select()">
                    <button type="button" class="btn btn-secondary" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('redeem-link').value)"><x-icon name="copy" :size="20" /> Copy</button>
                </div>
                <p class="form-help">This is the link your customer opens to visit. Share it with them if they did not get it by text.</p>
            </div>

            <div class="mt-4">
                <x-confirm-modal :action="route('referrals.regenerate', $referral)" title="Send a new redeem link?" :id="'regen-referral-'.$referral->id" confirm-label="Send new link" confirm-class="btn-primary">
                    <x-slot name="trigger">
                        <button type="button" class="btn btn-secondary">Regenerate redeem link</button>
                    </x-slot>
                    <p>The old link stops working right away. The referral keeps its place; only the link changes.</p>
                </x-confirm-modal>
            </div>
        @endif
    </div>

    <div class="panel mt-4">
        <h2 class="type-title-3 text-text-1">What happened</h2>
        @if ($events->isEmpty())
            <p class="mt-1 text-text-2">Nothing has happened yet.</p>
        @else
            <ol class="relative mt-4 space-y-4 border-l border-line pl-8">
                @foreach ($events as $event)
                    <li class="relative">
                        <span class="absolute top-0 -left-8 flex h-8 w-8 -translate-x-1/2 items-center justify-center rounded-full bg-surface-2 text-text-3"><x-icon name="info" :size="16" /></span>
                        <span class="type-body-sm block font-semibold text-text-1">{{ \App\Support\ReferralTimeline::label($event->event_type) }}</span>
                        <span class="type-caption block text-text-3">{{ $event->created_at->format('M j, Y, g:i A') }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
@endsection
