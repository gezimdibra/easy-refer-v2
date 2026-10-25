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
        <ul role="tablist" class="flex flex-wrap gap-2"
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
            <div class="overflow-x-auto"><table class="tbl tbl-sticky tbl-stack">
                <thead>
                    <tr>
                        <th scope="col">Friend</th>
                        <th scope="col">Referred by</th>
                        <th scope="col">Started</th>
                        <th scope="col">Visit</th>
                        <th scope="col">Reward status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($referrals as $referral)
                        @php($status = \App\Support\ReferralStatus::present($referral))
                        <tr>
                            <td>
                                <a class="card-link font-semibold text-link hover:underline" href="{{ route('referrals.show', $referral) }}">{{ $referral->referredCustomer->first_name }}</a>
                                @if ($referral->hold)
                                    <span class="block text-sm text-text-2">Held for review: {{ implode(' ', \App\Support\HoldReasons::sentences($referral->hold_rules ?? [], $business)) }} <a class="relative z-10 inline-flex min-h-12 items-center font-semibold text-link hover:underline" href="{{ route('reviews') }}">Open the review queue</a></span>
                                @endif
                            </td>
                            <td data-label="Referred by">{{ $referral->referrerMembership->customer->first_name }}</td>
                            <td class="whitespace-nowrap" data-label="Started">{{ $referral->created_at->format('M j, Y') }}</td>
                            <td data-label="Visit">
                                @if ($referral->state === \App\Models\Referral::STATE_REDEEMED)
                                    <span class="whitespace-nowrap">{{ $referral->redeemed_at?->format('M j, Y') }}</span>
                                @else
                                    @php($visit = \App\Support\ReferralStatus::visitPresent($referral))
                                    <span class="pill {{ $visit['pill'] }}">{{ $visit['label'] }}</span>
                                @endif
                            </td>
                            <td data-label="Reward status">
                                @if ($referral->reward !== null)
                                    <span class="pill {{ \App\Support\RewardStatus::present($referral->reward)['pill'] }}">{{ \App\Support\RewardStatus::present($referral->reward)['label'] }}</span>
                                @else
                                    No reward yet
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div class="border-t border-line px-4 py-3">{{ $referrals->links() }}</div>
        @endif
    </div>
@endsection
