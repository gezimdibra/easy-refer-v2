@extends('layouts.app')

@section('title', 'Customer detail')

@section('content')
    {{-- Customer detail (design Section 3.4): this business's view
         of one membership. Nothing from any other business is
         reachable from here (Section 7.3). --}}
    <p><a class="font-semibold text-brand-700 hover:underline" href="{{ route('customers.index') }}">Back to customers</a></p>

    <div class="card mt-3">
        <h1 class="text-2xl font-bold">{{ $membership->customer->first_name }} {{ $membership->customer->last_name }}</h1>
        {{-- The single-customer detail is the one owner surface
             where the full number renders (spec Section 5.1
             masking exception); every list stays masked. --}}
        <p class="mt-1 text-text-2">{{ \App\Support\PhoneNumber::formatForDisplay($membership->customer->phone_e164) }} · Joined {{ $membership->created_at->format('M j, Y') }}</p>
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
            <p class="mt-1 text-text-2">Consent last changed: {{ $consentChangedAt->format('M j, Y') }}</p>
        @endif
    </div>

    <div class="card mt-4">
        <h2 class="text-xl font-semibold">Referrals they made</h2>
        @if ($referralsMade->isEmpty())
            <p class="mt-1 text-text-2">No referrals yet.</p>
        @else
            <table class="mt-2 w-full text-left text-base">
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="py-2 pr-3 font-semibold">Friend</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Started</th>
                        <th scope="col" class="py-2 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($referralsMade as $referral)
                        <tr class="border-b border-line">
                            <td class="py-2 pr-3"><a class="font-semibold text-brand-700 hover:underline" href="{{ route('referrals.show', $referral) }}">{{ $referral->referredCustomer->first_name }}</a></td>
                            <td class="py-2 pr-3">{{ $referral->created_at->format('M j, Y') }}</td>
                            <td class="py-2"><span class="pill {{ \App\Support\ReferralStatus::present($referral)['pill'] }}">{{ \App\Support\ReferralStatus::present($referral)['label'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card mt-4">
        <h2 class="text-xl font-semibold">Rewards they earned</h2>
        @if ($rewards->isEmpty())
            <p class="mt-1 text-text-2">No rewards yet.</p>
        @else
            <table class="mt-2 w-full text-left text-base">
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="py-2 pr-3 font-semibold">Reward</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">For referral</th>
                        <th scope="col" class="py-2 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rewards as $reward)
                        <tr class="border-b border-line">
                            <td class="py-2 pr-3">{{ $reward->reward_text_snapshot }}</td>
                            <td class="py-2 pr-3">{{ $reward->referral?->referredCustomer?->first_name }}</td>
                            <td class="py-2"><span class="pill {{ \App\Support\RewardStatus::present($reward)['pill'] }}">{{ \App\Support\RewardStatus::present($reward)['label'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card mt-4">
        <h2 class="text-xl font-semibold">Visits they made</h2>
        @if ($visits->isEmpty())
            <p class="mt-1 text-text-2">No visits yet.</p>
        @else
            <table class="mt-2 w-full text-left text-base">
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="py-2 pr-3 font-semibold">Referred by</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Started</th>
                        <th scope="col" class="py-2 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($visits as $visit)
                        <tr class="border-b border-line">
                            <td class="py-2 pr-3">{{ $visit->referrerMembership->customer->first_name }}</td>
                            <td class="py-2 pr-3">{{ $visit->created_at->format('M j, Y') }}</td>
                            <td class="py-2"><span class="pill {{ \App\Support\ReferralStatus::present($visit)['pill'] }}">{{ \App\Support\ReferralStatus::present($visit)['label'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card mt-4">
        <h2 class="text-xl font-semibold">Remove from your store</h2>
        <p class="mt-1 text-text-2">This ends their connection to your store. It does not delete their Easy Refer record, and your history with them stays saved.</p>
        <div class="mt-3">
            <x-confirm-modal :action="route('customers.destroy', $membership)" method="DELETE" :title="'Remove '.$membership->customer->first_name.' '.$membership->customer->last_name.' from your store?'" :id="'remove-customer-'.$membership->id" confirm-label="Remove" cancel-label="Keep customer">
                <x-slot name="trigger">
                    <button type="button" class="btn btn-danger-ghost">Remove from your store</button>
                </x-slot>
                <p>This ends their connection to your store. It does not delete their Easy Refer record, and your history with them stays saved.</p>
            </x-confirm-modal>
        </div>
    </div>
@endsection
