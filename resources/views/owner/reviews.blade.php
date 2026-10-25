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
    <h1 class="type-title-2 text-text-1">Review</h1>
    <p class="type-body mt-2 max-w-[68ch] text-text-2">Items the automatic checks paused. Releasing resumes the normal flow. Denying is final: a denied referral never bills and no reward is paid.</p>

    <h2 class="type-title-3 mt-8 text-text-1">Needs review</h2>

    @forelse ($heldReferrals as $referral)
        <div class="panel mt-4">
            <p class="type-title-4 text-text-1">{{ $referral->referredCustomer->first_name }}, <span class="font-normal text-text-2">referred by {{ $referral->referrerMembership->customer->first_name }}</span></p>
            @foreach (\App\Support\HoldReasons::sentences($referral->hold_rules, $business) as $sentence)
                <p class="banner banner-warning mt-3">{{ $sentence }}</p>
            @endforeach
            <div class="mt-3 flex gap-2">
                <form method="POST" action="{{ route('reviews.referrals.release', ['referral' => $referral->id]) }}" data-loading>
                    @csrf
                    <button type="submit" class="btn btn-primary">Release</button>
                </form>
                <x-confirm-modal :action="route('reviews.referrals.deny', ['referral' => $referral->id])" title="Deny this referral?" :id="'deny-referral-'.$referral->id" confirm-label="Deny">
                    <x-slot name="trigger">
                        <button type="button" class="btn btn-danger-ghost">Deny</button>
                    </x-slot>
                    <p>This ends {{ $referral->referredCustomer->first_name }}'s referral. No charge is made and no reward is paid.</p>
                    <p>The customer is not notified automatically.</p>
                    <p class="font-semibold text-text-1">Denying is final and cannot be undone.</p>
                </x-confirm-modal>
            </div>
        </div>
    @empty
        <div class="mt-4 flex flex-col items-center rounded-lg border-[1.5px] border-dashed border-line-strong px-6 py-10 text-center">
            <span class="medallion"><x-icon name="clipboard-check" :size="24" /></span>
            <p class="type-title-3 mt-4 text-text-1">Nothing is waiting for review.</p>
            <p class="type-body-sm mt-1 text-text-2">Held items will appear here with the reason.</p>
        </div>
    @endforelse

    @foreach ($heldRewards as $reward)
        <div class="panel mt-4">
            <p class="type-title-4 text-text-1">{{ $reward->membership->customer->first_name }}'s reward: <span class="font-normal text-text-2">{{ $reward->reward_text_snapshot }}</span></p>
            @foreach (\App\Support\HoldReasons::sentences($reward->hold_rules, $business) as $sentence)
                <p class="banner banner-warning mt-3">{{ $sentence }}</p>
            @endforeach
            <div class="mt-3 flex gap-2">
                <form method="POST" action="{{ route('reviews.rewards.release', ['reward' => $reward->id]) }}" data-loading>
                    @csrf
                    <button type="submit" class="btn btn-primary">Release</button>
                </form>
                <x-confirm-modal :action="route('reviews.rewards.deny', ['reward' => $reward->id])" title="Deny this reward?" :id="'deny-reward-'.$reward->id" confirm-label="Deny">
                    <x-slot name="trigger">
                        <button type="button" class="btn btn-danger-ghost">Deny</button>
                    </x-slot>
                    <p>This ends {{ $reward->membership->customer->first_name }}'s reward of {{ $reward->reward_text_snapshot }}. It cannot be claimed.</p>
                    <p>The customer is not notified automatically.</p>
                    <p class="font-semibold text-text-1">Denying is final and cannot be undone.</p>
                </x-confirm-modal>
            </div>
        </div>
    @endforeach

    <h2 class="type-title-3 mt-10 text-text-1">Redeemed without a staff account</h2>
    <p class="type-body-sm mt-1 max-w-[68ch] text-text-2">These redemptions completed at the counter without a signed-in staff member. That is normal when staff use their own phones.</p>

    @forelse ($unattributed as $referral)
        <div class="panel mt-3">
            <p>{{ $referral->referredCustomer->first_name }}, referred by {{ $referral->referrerMembership->customer->first_name }}, redeemed {{ $referral->redeemed_at->format('F j, Y') }}</p>
        </div>
    @empty
        <div class="mt-3 flex items-center gap-3 rounded-lg border-[1.5px] border-dashed border-line-strong px-5 py-4">
            <span class="medallion"><x-icon name="info" :size="24" /></span>
            <p class="type-body-sm text-text-2">Nothing here yet. Redemptions that finish at the counter without a staff sign-in will be listed here.</p>
        </div>
    @endforelse

    <h2 class="type-title-3 mt-10 text-text-1">Pending number links</h2>
    <p class="type-body-sm mt-1 max-w-[68ch] text-text-2">Customers who confirmed a new number inside the cooling window. Their claims hold automatically until the window passes.</p>
    @forelse ($numberLinks as $event)
        <div class="panel mt-3">
            <p>{{ $event->customer_name ?? 'A customer' }} linked a new number on {{ $event->created_at->format('F j, Y') }}.</p>
        </div>
    @empty
        <div class="mt-3 flex items-center gap-3 rounded-lg border-[1.5px] border-dashed border-line-strong px-5 py-4">
            <span class="medallion"><x-icon name="info" :size="24" /></span>
            <p class="type-body-sm text-text-2">Nothing here yet. Customers who link a new number inside the cooling window will be listed here until the window passes.</p>
        </div>
    @endforelse

    <h2 class="type-title-3 mt-10 text-text-1">Rejected presentations</h2>
    <p class="type-body-sm mt-1 max-w-[68ch] text-text-2">Codes that could not be used when a customer presented them.</p>
    @forelse ($rejections as $event)
        <div class="panel mt-3">
            <p>A code that could not be used was presented on {{ $event->created_at->format('F j, Y') }}.</p>
        </div>
    @empty
        <div class="mt-3 flex items-center gap-3 rounded-lg border-[1.5px] border-dashed border-line-strong px-5 py-4">
            <span class="medallion"><x-icon name="info" :size="24" /></span>
            <p class="type-body-sm text-text-2">Nothing here yet. If a customer presents a code that cannot be used, it will be listed here with the date.</p>
        </div>
    @endforelse
@endsection
