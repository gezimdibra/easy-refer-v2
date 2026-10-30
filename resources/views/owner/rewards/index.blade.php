@extends('layouts.app')

@section('title', 'Rewards')


@section('content')
    {{-- Rewards (spec Section 5.4): the reward text is the
         mint-time snapshot, never the current settings text. The
         Text column is the notification state; a failed text can
         be sent again (REWL-03). --}}
    <h1 class="type-title-2 text-text-1">Rewards</h1>

    {{-- The filters are tabs (design 6.6), same pattern as the
         Referrals list. --}}
    <nav aria-label="Reward filters" class="mt-3">
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
                    <a role="tab" aria-selected="{{ $filter === $key ? 'true' : 'false' }}" href="{{ route('rewards.index', ['filter' => $key]) }}"
                        @if ($filter === $key) class="inline-flex min-h-[48px] items-center rounded-full border border-line-strong bg-brand-50 px-4 text-[15px] font-semibold text-link" @else class="inline-flex min-h-[48px] items-center rounded-full px-4 text-[15px] font-medium text-text-2 hover:bg-surface-1 hover:text-text-1" @endif>{{ $label }} ({{ $counts[$key] }})</a>
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="mt-4 overflow-hidden rounded-lg border border-line bg-surface-0 shadow-e1">
        @if ($rewards->isEmpty())
            <div class="flex flex-col items-center px-6 py-10 text-center">
                <span class="medallion"><x-icon name="gift" :size="24" /></span>
                @if ($filter !== 'all')
                    <p class="type-title-3 mt-4 text-text-1">No rewards in this view.</p>
                    <p class="mt-4"><a class="btn btn-secondary" href="{{ route('rewards.index') }}">Clear filters</a></p>
                @else
                    <p class="type-title-3 mt-4 text-text-1">No rewards yet.</p>
                @endif
            </div>
        @else
            {{-- RW-1: below md the reward leads each row at
                 semibold weight; the customer, friend, and (only
                 when it exists) the claimed date form one quiet
                 meta line beneath it. RW-2: a held row carries
                 its reason and the review-queue link. RW-3: a
                 failed text is a compact error caption plus the
                 small retry in the right column. --}}
            <ul class="divide-y divide-line md:hidden">
                @foreach ($rewards as $reward)
                    @php($status = \App\Support\RewardStatus::present($reward))
                    <li class="flex items-start justify-between gap-3 px-4 py-3 {{ $reward->hold ? 'border-l-[3px] border-warning-strong bg-warning-fill' : '' }}">
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-text-1">{{ $reward->reward_text_snapshot }}</span>
                            <span class="block text-sm text-text-2">{{ $reward->membership->customer->fullName() }} · for {{ $reward->referral?->referredCustomer?->first_name }}@if ($reward->state === \App\Models\Reward::STATE_CLAIMED && $reward->claimed_at !== null) · claimed {{ $business->localTime($reward->claimed_at)->format('M j, Y') }}@endif</span>
                            @if ($reward->hold)
                                <span class="mt-1 block text-sm text-warning-text">Held for review: {{ implode(' ', \App\Support\HoldReasons::sentencesForHold($reward->hold_rules ?? [], $business)) }} <a class="font-semibold text-link hover:underline" href="{{ route('reviews') }}">Open the review queue</a></span>
                            @endif
                            @if ($reward->notification_state !== 'failed')
                                <span class="type-caption mt-1 block text-text-3">Text: {{ \App\Support\RewardStatus::notificationLabel($reward) }}</span>
                            @endif
                        </span>
                        <span class="flex flex-none flex-col items-end gap-1">
                            <span class="pill {{ $status['pill'] }}">{{ $status['label'] }}</span>@if ($readyOn = \App\Support\RewardStatus::readyOn($reward))<span class="type-caption text-text-3">{{ $readyOn }}</span>@endif
                            @if ($goodUntil = \App\Support\RewardStatus::goodUntil($reward))<span class="type-caption text-text-3">{{ $goodUntil }}</span>@endif
                            @if ($reward->notification_state === 'failed')
                                <span class="type-caption text-error-strong">Text: {{ \App\Support\RewardStatus::notificationLabel($reward) }}</span>
                                <form method="POST" action="{{ route('rewards.resend', $reward) }}" data-loading>
                                    @csrf
                                    <button type="submit" class="btn btn-secondary btn-sm">Text again</button>
                                </form>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
            {{-- RW-4: on desktop the Claimed column folds into
                 the Status cell as a quiet subline and the
                 reward cell carries semibold weight. --}}
            <div class="hidden overflow-x-auto md:block"><table class="tbl tbl-sticky">
                <thead>
                    <tr>
                        <th scope="col">Customer</th>
                        <th scope="col">Reward</th>
                        <th scope="col">For referral</th>
                        <th scope="col">Status</th>
                        <th scope="col">Text</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rewards as $reward)
                        @php($status = \App\Support\RewardStatus::present($reward))
                        <tr class="{{ $reward->hold ? 'bg-warning-fill' : '' }}">
                            <td>
                                {{ $reward->membership->customer->fullName() }}
                                @if ($reward->hold)
                                    <span class="block text-sm text-warning-text">Held for review: {{ implode(' ', \App\Support\HoldReasons::sentencesForHold($reward->hold_rules ?? [], $business)) }} <a class="font-semibold text-link hover:underline" href="{{ route('reviews') }}">Open the review queue</a></span>
                                @endif
                            </td>
                            <td class="font-semibold text-text-1">{{ $reward->reward_text_snapshot }}</td>
                            <td>{{ $reward->referral?->referredCustomer?->first_name }}</td>
                            <td><span class="pill {{ $status['pill'] }}">{{ $status['label'] }}</span>@if ($readyOn = \App\Support\RewardStatus::readyOn($reward))<span class="type-caption block text-text-3">{{ $readyOn }}</span>@endif
                                @if ($goodUntil = \App\Support\RewardStatus::goodUntil($reward))<span class="type-caption block text-text-3">{{ $goodUntil }}</span>@endif
                                @if ($reward->state === \App\Models\Reward::STATE_CLAIMED && $reward->claimed_at !== null)<span class="type-caption block text-text-3">Claimed {{ $business->localTime($reward->claimed_at)->format('M j, Y') }}</span>@endif</td>
                            <td>
                                @if ($reward->notification_state === 'failed')
                                    <span class="type-caption block text-error-strong">{{ \App\Support\RewardStatus::notificationLabel($reward) }}</span>
                                    <form method="POST" action="{{ route('rewards.resend', $reward) }}" class="mt-1" data-loading>
                                        @csrf
                                        <button type="submit" class="btn btn-secondary btn-sm">Text again</button>
                                    </form>
                                @else
                                    {{ \App\Support\RewardStatus::notificationLabel($reward) }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            @if ($rewards->hasPages())
                <div class="border-t border-line px-4 py-3">{{ $rewards->links() }}</div>
            @endif
        @endif
    </div>
@endsection
