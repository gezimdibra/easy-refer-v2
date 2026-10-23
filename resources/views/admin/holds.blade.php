@extends('layouts.admin')

@section('title', 'Review holds')

@section('content')
    <h1 class="text-[28px] font-bold">Review holds (all businesses)</h1>
    <p class="mt-1 text-text-2">Read only. Owners release or deny holds in their own review queue.</p>

    <div class="card mt-4">
        @if ($referrals->isEmpty() && $rewards->isEmpty())
            <p class="text-text-2">No open holds.</p>
        @else
            <table class="w-full text-left text-base">
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="py-2 pr-3 font-semibold">Held</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Business</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Kind</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Customer</th>
                        <th scope="col" class="py-2 font-semibold">Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($referrals as $referral)
                        <tr class="border-b border-line">
                            <td class="py-2 pr-3">{{ $referral->held_at?->format('M j, Y, g:i A') }}</td>
                            <td class="py-2 pr-3">{{ $referral->business->name }}</td>
                            <td class="py-2 pr-3">Referral</td>
                            <td class="py-2 pr-3">{{ $referral->referredCustomer->first_name }}</td>
                            <td class="py-2">{{ \App\Support\HoldReasons::sentence($referral->hold_rules[0] ?? '', $referral->business) }}</td>
                        </tr>
                    @endforeach
                    @foreach ($rewards as $reward)
                        <tr class="border-b border-line">
                            <td class="py-2 pr-3">{{ $reward->held_at?->format('M j, Y, g:i A') }}</td>
                            <td class="py-2 pr-3">{{ $reward->business->name }}</td>
                            <td class="py-2 pr-3">Reward</td>
                            <td class="py-2 pr-3">{{ $reward->membership->customer->first_name }}</td>
                            <td class="py-2">{{ \App\Support\HoldReasons::sentence($reward->hold_rules[0] ?? '', $reward->business) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
