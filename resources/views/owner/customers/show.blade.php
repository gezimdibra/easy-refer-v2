@extends('layouts.app')

@section('title', 'Customer detail')

@section('content')
    {{-- Customer detail (design Section 3.4): this business's view
         of one membership. Nothing from any other business is
         reachable from here (Section 7.3). --}}
    <nav aria-label="Breadcrumb" class="type-caption flex min-h-12 items-center gap-1 text-text-2">
        <a class="text-text-2 hover:text-text-1" href="{{ route('customers.index') }}">Back to customers</a>
        <span aria-hidden="true"> / </span>
        <span aria-current="page" class="text-text-1">{{ $membership->customer->fullName() }}</span>
    </nav>

    <div class="panel mt-4">
        <h1 class="type-title-2 text-text-1">{{ $membership->customer->fullName() }}</h1>
        {{-- The single-customer detail is the one owner surface
             where the full number renders (spec Section 5.1
             masking exception); every list stays masked. --}}
        <p class="mt-1 text-text-2">{{ \App\Support\PhoneNumber::formatForDisplay($membership->customer->phone_e164) }} · Joined {{ $business->localTime($membership->created_at)->format('M j, Y') }}</p>
        <p class="mt-2">
            @if ($membership->sms_consent)
                <span class="pill pill-success">OK to text</span>
            @else
                <span class="pill pill-neutral">No texts</span>
            @endif
            @if ($membership->consent_source === 'owner_asserted')
                <span class="text-sm text-text-2">Added by you, waiting for their confirmation</span>
            @endif
        </p>
        @if ($consentChangedAt !== null)
            <p class="mt-1 text-text-2">Consent last changed: {{ $business->localTime($consentChangedAt)->format('M j, Y') }}</p>
        @endif
        {{-- CD-2: the stat strip answers what this customer is
             worth in the first screenful, counted from the data
             the page already loads. --}}
        <div class="mt-4 grid grid-cols-3 gap-2 sm:gap-3">
            <div class="rounded-lg bg-surface-1 p-3 sm:p-4">
                <p class="tile-label tile-label-compact">Referrals made</p>
                <p class="tile-value">{{ $referralsMade->count() }}</p>
            </div>
            <div class="rounded-lg bg-surface-1 p-3 sm:p-4">
                <p class="tile-label tile-label-compact">Rewards earned</p>
                <p class="tile-value">{{ $rewards->count() }}</p>
            </div>
            <div class="rounded-lg bg-surface-1 p-3 sm:p-4">
                <p class="tile-label tile-label-compact">Visits</p>
                <p class="tile-value">{{ $visits->count() }}</p>
            </div>
        </div>
        <p class="mt-3">
            <a class="btn btn-secondary" href="{{ route('customers.edit', $membership) }}">Edit details</a>
        </p>
    </div>

    {{-- CD-1: the histories are row lists (name, quiet date,
         pill), never squeezed tables: a two-element row cannot
         clip a pill or wrap a date at 375px. CD-4: an empty
         history is one quiet line in a slim panel. --}}
    @if ($referralsMade->isEmpty())
        <div class="panel mt-4" style="padding-top: 16px; padding-bottom: 16px">
            <div class="flex flex-wrap items-baseline gap-x-3">
                <h2 class="type-title-3 text-text-1">Referrals they made</h2>
                <p class="text-text-2">No referrals yet.</p>
            </div>
        </div>
    @else
        <div class="panel mt-4">
            <h2 class="type-title-3 text-text-1">Referrals they made</h2>
            <ul class="mt-3 divide-y divide-line">
                @foreach ($referralsMade as $referral)
                    <li class="flex items-center justify-between gap-4 py-3 {{ $referral->hold ? 'border-l-[3px] border-warning-strong bg-warning-fill pl-3' : '' }}">
                        <span class="min-w-0">
                            <a class="font-semibold text-link hover:underline" href="{{ route('referrals.show', $referral) }}">{{ $referral->referredCustomer->first_name }}</a>
                            <span class="type-caption block text-text-3">{{ $business->localTime($referral->created_at)->format('M j, Y') }}</span>
                            @if ($referral->hold)
                                {{-- CD-3: the held row explains itself
                                     and routes to the review queue,
                                     matching the referrals list. --}}
                                <span class="mt-1 block text-sm text-warning-text">Held for review: {{ implode(' ', \App\Support\HoldReasons::sentencesForHold($referral->hold_rules ?? [], $business)) }} <a class="font-semibold text-link hover:underline" href="{{ route('reviews') }}">Open the review queue</a></span>
                            @endif
                        </span>
                        <span class="pill {{ \App\Support\ReferralStatus::present($referral)['pill'] }} flex-none">{{ \App\Support\ReferralStatus::present($referral)['label'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($rewards->isEmpty())
        <div class="panel mt-4" style="padding-top: 16px; padding-bottom: 16px">
            <div class="flex flex-wrap items-baseline gap-x-3">
                <h2 class="type-title-3 text-text-1">Rewards they earned</h2>
                <p class="text-text-2">No rewards yet.</p>
            </div>
        </div>
    @else
        <div class="panel mt-4">
            <h2 class="type-title-3 text-text-1">Rewards they earned</h2>
            <ul class="mt-3 divide-y divide-line">
                @foreach ($rewards as $reward)
                    <li class="flex items-center justify-between gap-4 py-3">
                        <span class="min-w-0">
                            <span class="block font-semibold text-text-1">{{ $reward->reward_text_snapshot }}</span>
                            <span class="type-caption block text-text-3">{{ $reward->referral?->referredCustomer?->first_name }}</span>
                        </span>
                        <span class="flex-none text-right">
                            <span class="pill {{ \App\Support\RewardStatus::present($reward)['pill'] }}">{{ \App\Support\RewardStatus::present($reward)['label'] }}</span>@if ($readyOn = \App\Support\RewardStatus::readyOn($reward))<span class="type-caption block text-text-3">{{ $readyOn }}</span>@endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($visits->isEmpty())
        <div class="panel mt-4" style="padding-top: 16px; padding-bottom: 16px">
            <div class="flex flex-wrap items-baseline gap-x-3">
                <h2 class="type-title-3 text-text-1">Visits they made</h2>
                <p class="text-text-2">No visits yet.</p>
            </div>
        </div>
    @else
        <div class="panel mt-4">
            <h2 class="type-title-3 text-text-1">Visits they made</h2>
            <ul class="mt-3 divide-y divide-line">
                @foreach ($visits as $visit)
                    <li class="flex items-center justify-between gap-4 py-3">
                        <span class="min-w-0">
                            <span class="block font-semibold text-text-1">{{ $visit->referrerMembership->customer->first_name }}</span>
                            <span class="type-caption block text-text-3">{{ $business->localTime($visit->created_at)->format('M j, Y') }}</span>
                        </span>
                        <span class="pill {{ \App\Support\ReferralStatus::present($visit)['pill'] }} flex-none">{{ \App\Support\ReferralStatus::present($visit)['label'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- CD-5: the danger panel keeps its heading and button; the
         explanation lives in the confirm modal only, not twice. --}}
    <div class="panel mt-4">
        <h2 class="type-title-3 text-text-1">Remove from your store</h2>
        <div class="mt-3">
            <x-confirm-modal :action="route('customers.destroy', $membership)" method="DELETE" :title="'Remove '.$membership->customer->fullName().' from your store?'" :id="'remove-customer-'.$membership->id" confirm-label="Remove" cancel-label="Keep customer">
                <x-slot name="trigger">
                    <button type="button" class="btn btn-danger-ghost">Remove from your store</button>
                </x-slot>
                <p>This ends their connection to your store. It does not delete their Easy Refer record, and your history with them stays saved.</p>
            </x-confirm-modal>
        </div>
    </div>
@endsection
