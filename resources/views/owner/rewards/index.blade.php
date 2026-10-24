@extends('layouts.app')

@section('title', 'Rewards')

@section('notices_summary_only', '1')
@section('notices_banner_keys', 'reward')

@section('content')
    {{-- Rewards (spec Section 5.4): the reward text is the
         mint-time snapshot, never the current settings text. The
         Text column is the notification state; a failed text can
         be sent again (REWL-03). --}}
    <h1 class="type-title-2 text-text-1">Rewards</h1>

    {{-- The filters are tabs (design 6.6), same pattern as the
         Referrals list. --}}
    <nav aria-label="Reward filters" class="mt-3">
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
                    <a role="tab" aria-selected="{{ $filter === $key ? 'true' : 'false' }}" href="{{ route('rewards.index', ['filter' => $key]) }}"
                        @if ($filter === $key) class="inline-flex min-h-[48px] items-center rounded-full bg-brand-50 px-4 text-[15px] font-semibold text-brand-700" @else class="inline-flex min-h-[48px] items-center rounded-full px-4 text-[15px] font-medium text-text-2 hover:bg-surface-1 hover:text-text-1" @endif>{{ $label }} ({{ $counts[$key] }})</a>
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
            <div class="overflow-x-auto"><table class="tbl tbl-sticky tbl-stack">
                <thead>
                    <tr>
                        <th scope="col">Customer</th>
                        <th scope="col">Reward</th>
                        <th scope="col">For referral</th>
                        <th scope="col">Status</th>
                        <th scope="col">Claimed</th>
                        <th scope="col">Text</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rewards as $reward)
                        @php($status = \App\Support\RewardStatus::present($reward))
                        <tr>
                            <td>{{ $reward->membership->customer->first_name }} {{ $reward->membership->customer->last_name }}</td>
                            <td data-label="Reward">{{ $reward->reward_text_snapshot }}</td>
                            <td data-label="For referral">{{ $reward->referral?->referredCustomer?->first_name }}</td>
                            <td data-label="Status"><span class="pill {{ $status['pill'] }}">{{ $status['label'] }}</span></td>
                            <td class="whitespace-nowrap" data-label="Claimed">{{ $reward->claimed_at?->format('M j, Y') }}</td>
                            <td data-label="Text">
                                @if ($reward->notification_state === 'failed')
                                    <span class="text-error">{{ \App\Support\RewardStatus::notificationLabel($reward) }}</span>
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
            <div class="border-t border-line px-4 py-3">{{ $rewards->links() }}</div>
        @endif
    </div>
@endsection
