@extends('layouts.app')

@section('title', 'Referrals')

@section('content')
    {{-- Referrals (spec Section 5.3): state filters with counts;
         held rows carry the human reason inline and link to the
         review queue. --}}
    <h1 class="text-[28px] font-bold">Referrals</h1>

    {{-- The filters are tabs (design 6.6): arrow-key movement,
         Enter or Space activation, aria-selected state. --}}
    <nav aria-label="Referral filters" class="mt-3">
        <ul role="tablist" class="flex flex-wrap gap-x-4 gap-y-2 text-base"
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
                <li role="presentation">
                    <a role="tab" aria-selected="{{ $filter === $key ? 'true' : 'false' }}" href="{{ route('referrals.index', ['filter' => $key]) }}"
                        @if ($filter === $key) class="font-semibold text-brand-700 underline" @else class="font-semibold text-brand-700 hover:underline" @endif>{{ $label }} ({{ $counts[$key] }})</a>
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="card mt-4">
        @if ($referrals->isEmpty())
            @if ($filter !== 'all')
                <p class="text-text-2">No referrals in this view.</p>
                <p class="mt-2"><a class="btn btn-secondary" href="{{ route('referrals.index') }}">Clear filters</a></p>
            @else
                <p class="text-text-2">No referrals yet. Share your QR code to get started.</p>
            @endif
        @else
            <table class="w-full text-left text-base">
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="py-2 pr-3 font-semibold">Friend</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Referred by</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Started</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Visit</th>
                        <th scope="col" class="py-2 font-semibold">Reward status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($referrals as $referral)
                        @php($status = \App\Support\ReferralStatus::present($referral))
                        <tr class="border-b border-line">
                            <td class="py-2 pr-3">
                                <a class="font-semibold text-brand-700 hover:underline" href="{{ route('referrals.show', $referral) }}">{{ $referral->referredCustomer->first_name }}</a>
                                @if ($referral->hold)
                                    <span class="block text-sm text-text-2">Held for review: {{ implode(' ', \App\Support\HoldReasons::sentences($referral->hold_rules ?? [], $business)) }} <a class="font-semibold text-brand-700 hover:underline" href="{{ route('reviews') }}">Open the review queue</a></span>
                                @endif
                            </td>
                            <td class="py-2 pr-3">{{ $referral->referrerMembership->customer->first_name }}</td>
                            <td class="py-2 pr-3">{{ $referral->created_at->format('M j, Y') }}</td>
                            <td class="py-2 pr-3">
                                @if ($referral->state === \App\Models\Referral::STATE_REDEEMED)
                                    {{ $referral->redeemed_at?->format('M j, Y') }}
                                @else
                                    @php($visit = \App\Support\ReferralStatus::visitPresent($referral))
                                    <span class="pill {{ $visit['pill'] }}">{{ $visit['label'] }}</span>
                                @endif
                            </td>
                            <td class="py-2">
                                @if ($referral->reward !== null)
                                    <span class="pill {{ \App\Support\RewardStatus::present($referral->reward)['pill'] }}">{{ \App\Support\RewardStatus::present($referral->reward)['label'] }}</span>
                                @else
                                    No reward yet
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-3">{{ $referrals->links() }}</div>
        @endif
    </div>
@endsection
