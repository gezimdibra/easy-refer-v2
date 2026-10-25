@extends('layouts.app')

@section('title', 'Customer detail')

@section('content')
    {{-- Customer detail (design Section 3.4): this business's view
         of one membership. Nothing from any other business is
         reachable from here (Section 7.3). --}}
    <nav aria-label="Breadcrumb" class="type-caption flex min-h-12 items-center gap-1 text-text-2">
        <a class="text-text-2 hover:text-text-1" href="{{ route('customers.index') }}">Back to customers</a>
        <span aria-hidden="true"> / </span>
        <span aria-current="page" class="text-text-1">{{ $membership->customer->first_name }} {{ $membership->customer->last_name }}</span>
    </nav>

    <div class="panel mt-4">
        <h1 class="type-title-2 text-text-1">{{ $membership->customer->first_name }} {{ $membership->customer->last_name }}</h1>
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

    <div class="panel mt-4">
        <h2 class="type-title-3 text-text-1">Referrals they made</h2>
        @if ($referralsMade->isEmpty())
            <p class="mt-1 text-text-2">No referrals yet.</p>
        @else
            <div class="mt-3 overflow-x-auto"><table class="tbl">
                <thead>
                    <tr>
                        <th scope="col">Friend</th>
                        <th scope="col">Started</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($referralsMade as $referral)
                        <tr>
                            <td><a class="font-semibold text-link hover:underline" href="{{ route('referrals.show', $referral) }}">{{ $referral->referredCustomer->first_name }}</a></td>
                            <td>{{ $referral->created_at->format('M j, Y') }}</td>
                            <td><span class="pill {{ \App\Support\ReferralStatus::present($referral)['pill'] }}">{{ \App\Support\ReferralStatus::present($referral)['label'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>

    <div class="panel mt-4">
        <h2 class="type-title-3 text-text-1">Rewards they earned</h2>
        @if ($rewards->isEmpty())
            <p class="mt-1 text-text-2">No rewards yet.</p>
        @else
            <div class="mt-3 overflow-x-auto"><table class="tbl">
                <thead>
                    <tr>
                        <th scope="col">Reward</th>
                        <th scope="col">For referral</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rewards as $reward)
                        <tr>
                            <td>{{ $reward->reward_text_snapshot }}</td>
                            <td>{{ $reward->referral?->referredCustomer?->first_name }}</td>
                            <td><span class="pill {{ \App\Support\RewardStatus::present($reward)['pill'] }}">{{ \App\Support\RewardStatus::present($reward)['label'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>

    <div class="panel mt-4">
        <h2 class="type-title-3 text-text-1">Visits they made</h2>
        @if ($visits->isEmpty())
            <p class="mt-1 text-text-2">No visits yet.</p>
        @else
            <div class="mt-3 overflow-x-auto"><table class="tbl">
                <thead>
                    <tr>
                        <th scope="col">Referred by</th>
                        <th scope="col">Started</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($visits as $visit)
                        <tr>
                            <td>{{ $visit->referrerMembership->customer->first_name }}</td>
                            <td>{{ $visit->created_at->format('M j, Y') }}</td>
                            <td><span class="pill {{ \App\Support\ReferralStatus::present($visit)['pill'] }}">{{ \App\Support\ReferralStatus::present($visit)['label'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>

    <div class="panel mt-4">
        <h2 class="type-title-3 text-text-1">Remove from your store</h2>
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
