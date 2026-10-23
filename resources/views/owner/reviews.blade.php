@extends('layouts.app')

@section('title', 'Review')

@section('content')
    {{-- The review queue (spec Sections 3.1 and 5.6): held items
         with plain-sentence reasons and the two owner actions, then
         the unattributed redemptions and rejected presentations
         the counter produced, for visibility. Parties are named by
         first name (design 3.4). Deny opens the consequence modal
         (design 2.5) before anything fires: it is the product's
         only destructive confirmation (F-04). --}}
    <h1 class="text-[28px] font-bold">Review</h1>
    <p class="mt-1 text-text-2">Items the automatic checks paused. Releasing resumes the normal flow. Denying is final: a denied referral never bills and no reward is paid.</p>

    <h2 class="mt-6 text-xl font-semibold">Needs review</h2>

    @forelse ($heldReferrals as $referral)
        <div class="card mt-3">
            <p><strong>{{ $referral->referredCustomer->first_name }}</strong>, referred by {{ $referral->referrerMembership->customer->first_name }}</p>
            @foreach (\App\Support\HoldReasons::sentences($referral->hold_rules, $business) as $sentence)
                <p class="mt-1 text-text-2">{{ $sentence }}</p>
            @endforeach
            <div class="mt-3 flex gap-2">
                <form method="POST" action="{{ route('reviews.referrals.release', ['referral' => $referral->id]) }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Release</button>
                </form>
                <x-confirm-modal :action="route('reviews.referrals.deny', ['referral' => $referral->id])" title="Deny this referral?" :id="'deny-referral-'.$referral->id" confirm-label="Deny">
                    <x-slot name="trigger">
                        <button type="button" class="btn btn-ghost">Deny</button>
                    </x-slot>
                    <p>This ends {{ $referral->referredCustomer->first_name }}'s referral. No charge is made and no reward is paid.</p>
                    <p>The customer is not notified automatically.</p>
                    <p class="font-semibold text-text-1">Denying is final and cannot be undone.</p>
                </x-confirm-modal>
            </div>
        </div>
    @empty
        <p class="mt-2 text-text-2">Nothing is waiting for review.</p>
        <p class="mt-1 text-text-2">Held items will appear here with the reason.</p>
    @endforelse

    @foreach ($heldRewards as $reward)
        <div class="card mt-3">
            <p><strong>{{ $reward->membership->customer->first_name }}</strong>'s reward: {{ $reward->reward_text_snapshot }}</p>
            @foreach (\App\Support\HoldReasons::sentences($reward->hold_rules, $business) as $sentence)
                <p class="mt-1 text-text-2">{{ $sentence }}</p>
            @endforeach
            <div class="mt-3 flex gap-2">
                <form method="POST" action="{{ route('reviews.rewards.release', ['reward' => $reward->id]) }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Release</button>
                </form>
                <x-confirm-modal :action="route('reviews.rewards.deny', ['reward' => $reward->id])" title="Deny this reward?" :id="'deny-reward-'.$reward->id" confirm-label="Deny">
                    <x-slot name="trigger">
                        <button type="button" class="btn btn-ghost">Deny</button>
                    </x-slot>
                    <p>This ends {{ $reward->membership->customer->first_name }}'s reward of {{ $reward->reward_text_snapshot }}. It cannot be claimed.</p>
                    <p>The customer is not notified automatically.</p>
                    <p class="font-semibold text-text-1">Denying is final and cannot be undone.</p>
                </x-confirm-modal>
            </div>
        </div>
    @endforeach

    <h2 class="mt-8 text-xl font-semibold">Redeemed without a staff account</h2>
    <p class="mt-1 text-text-2">These redemptions completed at the counter without a signed-in staff member. That is normal when staff use their own phones.</p>

    @forelse ($unattributed as $referral)
        <div class="card mt-3">
            <p>{{ $referral->referredCustomer->first_name }}, referred by {{ $referral->referrerMembership->customer->first_name }}, redeemed {{ $referral->redeemed_at->format('F j, Y') }}</p>
        </div>
    @empty
        <p class="mt-2 text-text-2">None yet.</p>
    @endforelse

    <h2 class="mt-8 text-xl font-semibold">Pending number links</h2>
    <p class="mt-1 text-text-2">Customers who verified with a new number inside the cooling window. Their claims hold automatically until the window passes.</p>
    @forelse ($numberLinks as $event)
        <div class="card mt-3">
            <p>{{ $event->customer_name ?? 'A customer' }} linked a new number on {{ $event->created_at->format('F j, Y') }}.</p>
        </div>
    @empty
        <p class="mt-2 text-text-2">None yet.</p>
    @endforelse

    <h2 class="mt-8 text-xl font-semibold">Rejected presentations</h2>
    @forelse ($rejections as $event)
        <div class="card mt-3">
            <p>A code that could not be used was presented on {{ $event->created_at->format('F j, Y') }}.</p>
        </div>
    @empty
        <p class="mt-2 text-text-2">None yet.</p>
    @endforelse
@endsection
