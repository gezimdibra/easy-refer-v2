@extends('layouts.app')

@section('title', 'Referral detail')


@section('content')
    {{-- Referral detail (spec Section 5.3): the timeline is the
         referral_events ledger for this referral, in order, with
         the inventory's event labels (REFL-06). --}}
    <nav aria-label="Breadcrumb" class="type-caption flex min-h-12 items-center gap-1 text-text-2">
        <a class="text-text-2 hover:text-text-1" href="{{ route('referrals.index') }}">Back to referrals</a>
        <span aria-hidden="true"> / </span>
        <span aria-current="page" class="text-text-1">Referral detail</span>
    </nav>

    <div class="panel mt-4">
        {{-- RD-1: the page is titled with its subject, not its
             object type; the started date becomes the subline. --}}
        <h1 class="type-title-2 text-text-1">{{ $referral->referredCustomer->first_name }}, referred by {{ $referral->referrerMembership->customer->first_name }}</h1>
        <p class="mt-1 text-text-2">Started {{ $business->localTime($referral->created_at)->format('M j, Y') }}</p>
        @if ($referral->state === \App\Models\Referral::STATE_PENDING && $referral->expires_at !== null)
            {{-- SA-03 (Amendment 35): the visit deadline, in the
                 customer's own words (the redeem page's line),
                 in the business's local time. State-truth walk:
                 a held referral's stored date can already be
                 past (held referrals no longer expire); that
                 state states the ended truth instead. An
                 unheld past-date pending row cannot persist
                 here: applyExpiry() ran on this read. --}}
            @if ($referral->hold && $referral->expires_at->isPast())
                <p class="mt-1 text-text-2">Visit window ended {{ $business->localTime($referral->expires_at)->format('F j, Y') }} while held for review.</p>
            @else
                <p class="mt-1 text-text-2">Good until {{ $business->localTime($referral->expires_at)->format('F j, Y') }}</p>
            @endif
        @endif
        {{-- RD-2: the two pills belong to two different objects,
             so each carries its caption. RT-1 (owner correction,
             2026-10-27): the strip now names BOTH rewards by
             their owner — the friend's (given at the visit, no
             wait) and the referrer's (the vesting Reward row) —
             because the bare "Reward" caption beside a vesting
             date read as the friend waiting for a reward that
             was handed over at the counter. --}}
        <div class="mt-3 flex flex-wrap items-start gap-x-6 gap-y-2">
            <span class="flex items-center gap-2"><span class="type-caption text-text-3">Referral</span><span class="pill {{ \App\Support\ReferralStatus::present($referral)['pill'] }}">{{ \App\Support\ReferralStatus::present($referral)['label'] }}</span></span>
            @php($friendLine = \App\Support\ReferralRewardLines::friend($referral))
            <span>
                <span class="flex items-center gap-2"><span class="type-caption text-text-3">{{ $friendLine['label'] }}</span><span class="type-body-sm font-semibold text-text-1">{{ $friendLine['text'] }}</span></span>
                @if ($friendLine['note'] !== '')
                    <span class="type-caption block text-text-3">{{ $friendLine['note'] }}</span>
                @endif
            </span>
            @if ($referral->reward !== null)
                <span>
                    <span class="flex items-center gap-2"><span class="type-caption text-text-3">{{ \App\Support\ReferralRewardLines::referrerLabel($referral) }}</span><span class="pill {{ \App\Support\RewardStatus::present($referral->reward)['pill'] }}">{{ \App\Support\RewardStatus::present($referral->reward)['label'] }}</span></span>
                    @if ($readyOn = \App\Support\RewardStatus::readyOn($referral->reward))
                        <span class="type-caption block text-text-3">{{ $readyOn }}</span>
                    @endif
                </span>
            @endif
        </div>
        {{-- REFL-13 (Amendment 44): the fee line, present for
             every referral state — the detail page carries the
             complete fee truth the list abbreviates. --}}
        <p class="mt-3 text-text-2">{{ $feeLine }}</p>
        @if ($referral->hold && $holdReasons !== [])
            {{-- RD-3: the one state demanding action gets the
                 warning banner, not body text. --}}
            <div class="banner banner-warning mt-4">
                <p>Held for review: {{ implode(' ', $holdReasons) }} <a class="inline-flex min-h-12 items-center font-semibold text-link hover:underline" href="{{ route('reviews') }}">Open the review queue</a></p>
            </div>
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
                        <button type="button" class="btn btn-ghost btn-sm">Regenerate redeem link</button>
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
            {{-- RD-4: nodes are colored by event class and each
                 rail segment is drawn by its own item, so the
                 rail ends at the final node. --}}
            <ol class="relative mt-4 space-y-4 pl-8">
                @foreach ($events as $event)
                    @php($tone = \App\Support\ReferralTimeline::nodeTone($event->event_type))
                    <li class="relative">
                        @unless ($loop->last)
                            <span class="timeline-rail absolute top-4 -bottom-8 -left-8 w-px bg-line" aria-hidden="true"></span>
                        @endunless
                        <span class="absolute top-0 -left-8 flex h-8 w-8 -translate-x-1/2 items-center justify-center rounded-full {{ $tone === 'success' ? 'bg-success-fill text-success-text' : ($tone === 'warning' ? 'bg-warning-fill text-warning-text' : 'bg-surface-2 text-text-3') }}"><x-icon name="info" :size="16" /></span>
                        <span class="type-body-sm block font-semibold text-text-1">{{ \App\Support\ReferralTimeline::label($event->event_type) }}</span>
                        <span class="type-caption block text-text-3">{{ $business->localTime($event->created_at)->format('M j, Y, g:i A') }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
@endsection
