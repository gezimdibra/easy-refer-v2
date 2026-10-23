@extends('layouts.app')

@section('title', 'Referral detail')

@section('notices_summary_only', '1')
@section('notices_banner_keys', 'referral')

@section('content')
    {{-- Referral detail (spec Section 5.3): the timeline is the
         referral_events ledger for this referral, in order, with
         the inventory's event labels (REFL-06). --}}
    <p><a class="font-semibold text-brand-700 hover:underline" href="{{ route('referrals.index') }}">Back to referrals</a></p>

    <div class="card mt-3">
        <h1 class="text-[28px] font-bold">Referral detail</h1>
        <p class="mt-1 text-text-2">{{ $referral->referredCustomer->first_name }}, referred by {{ $referral->referrerMembership->customer->first_name }} · Started {{ $referral->created_at->format('M j, Y') }}</p>
        <p class="mt-2">
            <span class="pill {{ \App\Support\ReferralStatus::present($referral)['pill'] }}">{{ \App\Support\ReferralStatus::present($referral)['label'] }}</span>
            @if ($referral->reward !== null)
                <span class="pill {{ \App\Support\RewardStatus::present($referral->reward)['pill'] }}">{{ \App\Support\RewardStatus::present($referral->reward)['label'] }}</span>
            @endif
        </p>
        @if ($referral->hold && $holdReasons !== [])
            <p class="mt-2 text-text-2">Held for review: {{ implode(' ', $holdReasons) }} <a class="font-semibold text-brand-700 hover:underline" href="{{ route('reviews') }}">Open the review queue</a></p>
        @endif

        @if ($redeemUrl !== null)
            <div class="mt-4">
                <label for="redeem-link">Redeem link</label>
                <input id="redeem-link" type="text" readonly value="{{ $redeemUrl }}" onclick="this.select()">
                <p class="mt-1 text-text-2">This is the link your customer opens to visit. Share it with them if they did not get it by text.</p>
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

    <div class="card mt-4">
        <h2 class="text-xl font-semibold">What happened</h2>
        @if ($events->isEmpty())
            <p class="mt-1 text-text-2">Nothing has happened yet.</p>
        @else
            <ol class="mt-2 space-y-2">
                @foreach ($events as $event)
                    <li>
                        <span class="font-semibold">{{ \App\Support\ReferralTimeline::label($event->event_type) }}</span>
                        <span class="text-text-2">{{ $event->created_at->format('M j, Y, g:i A') }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
@endsection
