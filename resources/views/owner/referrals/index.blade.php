@extends('layouts.app')

@section('title', 'Referrals')

@section('content')
    {{-- Referrals (spec Section 5.3): state filters with counts;
         held rows carry the human reason inline and link to the
         review queue. --}}
    <h1 class="type-title-2 text-text-1">Referrals</h1>

    {{-- The filters are tabs (design 6.6): arrow-key movement,
         Enter or Space activation, aria-selected state. --}}
    <nav aria-label="Referral filters" class="mt-3">
        <ul role="tablist" class="flex flex-nowrap gap-2 overflow-x-auto pb-1"
            x-data="{
                tabKeys(event) {
                    const tabs = Array.from(event.currentTarget.querySelectorAll('[role=tab]'));
                    const index = tabs.indexOf(document.activeElement);
                    if (index === -1) { return; }
                    let next = null;
                    if (event.key === 'ArrowRight') { next = tabs[(index + 1) % tabs.length]; }
                    if (event.key === 'ArrowLeft') { next = tabs[(index - 1 + tabs.length) % tabs.length]; }
                    if (event.key === 'Home') { next = tabs[0]; }
                    if (event.key === 'End') { next = tabs[tabs.length - 1]; }
                    if (next) { event.preventDefault(); next.focus(); }
                    if (event.key === ' ') { event.preventDefault(); document.activeElement.click(); }
                }
            }"
            @keydown="tabKeys($event)">
            @foreach ($filters as $key => $label)
                <li role="presentation" class="flex-none">
                    <a role="tab" aria-selected="{{ $filter === $key ? 'true' : 'false' }}" href="{{ route('referrals.index', ['filter' => $key]) }}"
                        @if ($filter === $key) class="inline-flex min-h-[48px] items-center rounded-full border border-line-strong bg-brand-50 px-4 text-[15px] font-semibold text-link" @else class="inline-flex min-h-[48px] items-center rounded-full px-4 text-[15px] font-medium text-text-2 hover:bg-surface-1 hover:text-text-1" @endif>{{ $label }} ({{ $counts[$key] }})</a>
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="mt-4 overflow-hidden rounded-lg border border-line bg-surface-0 shadow-e1">
        @if ($referrals->isEmpty())
            <div class="flex flex-col items-center px-6 py-10 text-center">
                <span class="medallion"><x-icon name="share-2" :size="24" /></span>
                @if ($filter !== 'all')
                    <p class="type-title-3 mt-4 text-text-1">No referrals in this view.</p>
                    <p class="mt-4"><a class="btn btn-secondary" href="{{ route('referrals.index') }}">Clear filters</a></p>
                @else
                    <p class="type-title-3 mt-4 text-text-1">No referrals yet. Share your QR code to get started.</p>
                @endif
            </div>
        @else
            {{-- RF-2: below md each referral is a journey row:
                 the friend, a meta line (by, started, visited),
                 one primary pill at the right (Held wins), and a
                 labeled Reward line only when a reward exists.
                 "No reward yet" renders nothing. --}}
            <ul class="divide-y divide-line md:hidden">
                @foreach ($referrals as $referral)
                    @php($status = \App\Support\ReferralStatus::present($referral))
                    @php($windowLine = $referral->state === \App\Models\Referral::STATE_PENDING && $referral->expires_at !== null
                        ? ($referral->hold && $referral->expires_at->isPast()
                            ? 'Visit window ended '.$business->localTime($referral->expires_at)->format('F j, Y').' while held for review.'
                            : 'Good until '.$business->localTime($referral->expires_at)->format('F j, Y'))
                        : null)
                    <li class="relative flex items-start justify-between gap-3 px-4 py-3 {{ $referral->hold ? 'border-l-[3px] border-warning-strong bg-warning-fill' : '' }}">
                        <span class="min-w-0">
                            <a class="font-semibold text-link hover:underline after:absolute after:inset-0" href="{{ route('referrals.show', $referral) }}">{{ $referral->referredCustomer->first_name }}</a>
                            <span class="block text-sm text-text-2">by {{ $referral->referrerMembership->customer->first_name }} · started {{ $business->localTime($referral->created_at)->format('M j, Y') }}@if ($referral->state === \App\Models\Referral::STATE_REDEEMED && $referral->redeemed_at !== null) · visited {{ $business->localTime($referral->redeemed_at)->format('M j, Y') }}@endif</span>
                            @if ($windowLine !== null)
                                <span class="block text-sm text-text-2">{{ $windowLine }}</span>
                            @endif
                            @if (($feeLines[$referral->id]['list'] ?? null) !== null)
                                {{-- REFL-13 (Amendment 44): the fee subline, redeemed rows only; pending/expired/voided rows gain no line. --}}
                                <span class="block text-sm text-text-2">{{ $feeLines[$referral->id]['list'] }}</span>
                            @endif
                            @if ($referral->reward !== null)
                                <span class="mt-1 flex items-center gap-2"><span class="type-caption text-text-3">{{ $referral->referrerMembership->customer->first_name }}'s reward</span><span class="pill {{ \App\Support\RewardStatus::present($referral->reward)['pill'] }}">{{ \App\Support\RewardStatus::present($referral->reward)['label'] }}</span></span>@if ($readyOn = \App\Support\RewardStatus::readyOn($referral->reward))<span class="type-caption block text-text-3">{{ $readyOn }}</span>@endif
                            @endif
                            @if ($referral->hold)
                                <span class="mt-1 block text-sm text-warning-text">Held for review: {{ implode(' ', \App\Support\HoldReasons::sentencesForHold($referral->hold_rules ?? [], $business)) }} <a class="relative z-10 inline-flex min-h-12 items-center font-semibold text-link hover:underline" href="{{ route('reviews') }}">Open the review queue</a></span>
                            @endif
                        </span>
                        <span class="pill {{ $status['pill'] }} flex-none">{{ $status['label'] }}</span>
                    </li>
                @endforeach
            </ul>
            {{-- RF-3: on desktop the reward folds into the Status
                 cell as a labeled subline; the REWARD STATUS
                 column is gone and held rows are tinted. --}}
            <div class="hidden overflow-x-auto md:block"><table class="tbl tbl-sticky">
                <thead>
                    <tr>
                        <th scope="col">Friend</th>
                        <th scope="col">Referred by</th>
                        <th scope="col">Started</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($referrals as $referral)
                        @php($status = \App\Support\ReferralStatus::present($referral))
                        @php($windowLine = $referral->state === \App\Models\Referral::STATE_PENDING && $referral->expires_at !== null
                            ? ($referral->hold && $referral->expires_at->isPast()
                                ? 'Visit window ended '.$business->localTime($referral->expires_at)->format('F j, Y').' while held for review.'
                                : 'Good until '.$business->localTime($referral->expires_at)->format('F j, Y'))
                            : null)
                        <tr class="{{ $referral->hold ? 'bg-warning-fill' : '' }}">
                            <td>
                                <a class="font-semibold text-link hover:underline" href="{{ route('referrals.show', $referral) }}">{{ $referral->referredCustomer->first_name }}</a>
                                @if ($referral->hold)
                                    <span class="block text-sm text-text-2">Held for review: {{ implode(' ', \App\Support\HoldReasons::sentencesForHold($referral->hold_rules ?? [], $business)) }} <a class="relative z-10 inline-flex min-h-12 items-center font-semibold text-link hover:underline" href="{{ route('reviews') }}">Open the review queue</a></span>
                                @endif
                            </td>
                            <td>{{ $referral->referrerMembership->customer->first_name }}</td>
                            <td class="whitespace-nowrap">{{ $business->localTime($referral->created_at)->format('M j, Y') }}@if ($windowLine !== null)<span class="block whitespace-normal text-sm text-text-2">{{ $windowLine }}</span>@endif</td>
                            <td>
                                @if ($referral->state === \App\Models\Referral::STATE_REDEEMED)
                                    <span class="whitespace-nowrap">{{ $referral->redeemed_at !== null ? $business->localTime($referral->redeemed_at)->format('M j, Y') : '' }}</span>
                                @else
                                    <span class="pill {{ $status['pill'] }}">{{ $status['label'] }}</span>
                                @endif
                                @if ($referral->reward !== null)
                                    <span class="mt-1 flex items-center gap-2"><span class="type-caption text-text-3">{{ $referral->referrerMembership->customer->first_name }}'s reward</span><span class="pill {{ \App\Support\RewardStatus::present($referral->reward)['pill'] }}">{{ \App\Support\RewardStatus::present($referral->reward)['label'] }}</span></span>@if ($readyOn = \App\Support\RewardStatus::readyOn($referral->reward))<span class="type-caption block text-text-3">{{ $readyOn }}</span>@endif
                                @endif
                                @if (($feeLines[$referral->id]['list'] ?? null) !== null)
                                    {{-- REFL-13 (Amendment 44): the fee folds into the Status cell as a labeled subline (RF-3 grammar); there is no Fee column. --}}
                                    <span class="mt-1 block text-sm text-text-2">{{ $feeLines[$referral->id]['list'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            @if ($referrals->hasPages())
                <div class="border-t border-line px-4 py-3">{{ $referrals->links() }}</div>
            @endif
        @endif
    </div>
@endsection
