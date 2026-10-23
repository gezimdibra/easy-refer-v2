@extends('layouts.app')

@section('title', 'Rewards')

@section('notices_summary_only', '1')
@section('notices_banner_keys', 'reward')

@section('content')
    {{-- Rewards (spec Section 5.4): the reward text is the
         mint-time snapshot, never the current settings text. The
         Text column is the notification state; a failed text can
         be sent again (REWL-03). --}}
    <h1 class="text-2xl font-bold">Rewards</h1>

    {{-- The filters are tabs (design 6.6), same pattern as the
         Referrals list. --}}
    <nav aria-label="Reward filters" class="mt-3">
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
                    <a role="tab" aria-selected="{{ $filter === $key ? 'true' : 'false' }}" href="{{ route('rewards.index', ['filter' => $key]) }}"
                        @if ($filter === $key) class="font-semibold text-brand-700 underline" @else class="font-semibold text-brand-700 hover:underline" @endif>{{ $label }} ({{ $counts[$key] }})</a>
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="card mt-4">
        @if ($rewards->isEmpty())
            @if ($filter !== 'all')
                <p class="text-text-2">No rewards in this view.</p>
                <p class="mt-2"><a class="btn btn-secondary" href="{{ route('rewards.index') }}">Clear filters</a></p>
            @else
                <p class="text-text-2">No rewards yet.</p>
            @endif
        @else
            <table class="w-full text-left text-base">
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="py-2 pr-3 font-semibold">Customer</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Reward</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">For referral</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Status</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Claimed</th>
                        <th scope="col" class="py-2 font-semibold">Text</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rewards as $reward)
                        @php($status = \App\Support\RewardStatus::present($reward))
                        <tr class="border-b border-line">
                            <td class="py-2 pr-3">{{ $reward->membership->customer->first_name }} {{ $reward->membership->customer->last_name }}</td>
                            <td class="py-2 pr-3">{{ $reward->reward_text_snapshot }}</td>
                            <td class="py-2 pr-3">{{ $reward->referral?->referredCustomer?->first_name }}</td>
                            <td class="py-2 pr-3"><span class="pill {{ $status['pill'] }}">{{ $status['label'] }}</span></td>
                            <td class="py-2 pr-3">{{ $reward->claimed_at?->format('M j, Y') }}</td>
                            <td class="py-2">
                                @if ($reward->notification_state === 'failed')
                                    <span class="text-error">{{ \App\Support\RewardStatus::notificationLabel($reward) }}</span>
                                    <form method="POST" action="{{ route('rewards.resend', $reward) }}" class="mt-1">
                                        @csrf
                                        <button type="submit" class="btn btn-secondary">Text again</button>
                                    </form>
                                @else
                                    {{ \App\Support\RewardStatus::notificationLabel($reward) }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-3">{{ $rewards->links() }}</div>
        @endif
    </div>
@endsection
