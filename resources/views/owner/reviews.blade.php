@extends('layouts.app')

@section('title', 'Review')

@section('content')
    {{-- The review page (spec Sections 3.1 and 5.6, copy
         Amendment 31): two zones. First the "Needs review"
         decision queue: held items with plain-sentence reasons
         and the two owner actions. Then the "Worth knowing"
         records zone: counter redemptions, new phone numbers,
         and codes that could not be used, for visibility only.
         Parties are named by first name (design 3.4). Deny opens
         the consequence modal (design 2.5) before anything
         fires: it is the product's only destructive confirmation
         (F-04). --}}
    <h1 class="type-title-2 text-text-1">Review</h1>
    <p class="type-body mt-2 max-w-[68ch] text-text-2">Items the automatic checks paused. Releasing resumes the normal flow. Denying is final: a denied referral never bills and no reward is paid.</p>

    <h2 class="type-title-3 mt-8 text-text-1">Needs review</h2>
    @if ($queue->isNotEmpty())
        <p class="type-body-sm mt-1 text-text-2">{{ $queue->count() }} waiting{{ $oldestAge !== null ? ' · oldest '.$oldestAge : '' }}</p>
        @if ($hasWarningAge)
            <p class="type-body-sm mt-1 text-text-2">Items waiting 7 days or more have a highlighted waiting pill.</p>
        @endif
    @endif

    {{-- The empty state belongs to the whole queue (held
         referrals AND held rewards together, T5-F3): it renders
         only when both are empty, so it can never sit above a
         held item awaiting Release/Deny. --}}
    @foreach ($queue as $entry)
        @php($heldDays = $entry['held_at'] !== null ? (int) $entry['held_at']->diffInDays(now()) : 0)
        <div class="panel mt-4">
            <div class="flex flex-wrap items-center gap-2">
                @if ($entry['type'] === 'referral')
                    <span class="pill pill-info">Referral</span>
                @else
                    <span class="pill pill-neutral">Reward</span>
                @endif
                @if ($entry['held_at'] !== null)
                    <span class="pill {{ $heldDays >= 7 ? 'pill-warning' : 'pill-neutral' }}">Waiting {{ \App\Support\AgeLabel::of($entry['held_at']) }}</span>
                @endif
            </div>
            @if ($entry['type'] === 'referral')
                @php($referral = $entry['item'])
                <p class="type-title-4 mt-3 text-text-1">{{ $referral->referredCustomer->first_name }}, <span class="font-normal text-text-2">referred by {{ $referral->referrerMembership->customer->first_name }}</span></p>
                @foreach (\App\Support\HoldReasons::sentencesForHold($referral->hold_rules, $business) as $sentence)
                    <p class="banner banner-warning mt-3">{{ $sentence }}</p>
                @endforeach
                @if ($referral->expires_at !== null)
                    {{-- SA-03 (Amendment 35): the visit deadline
                         beside the decisions it affects, in the
                         business's local time. State-truth walk:
                         a held referral no longer expires, so
                         its stored date can already be past;
                         that state states the ended truth and
                         the true release consequence (the
                         fresh-window top-up), never a future
                         tense for a past date. --}}
                    @if ($referral->expires_at->isPast())
                        <p class="type-body-sm mt-3 text-text-2">Visit window ended {{ $business->localTime($referral->expires_at)->format('F j, Y') }} while this waited. Releasing gives the friend a fresh window.</p>
                    @else
                        <p class="type-body-sm mt-3 text-text-2">Visit window ends {{ $business->localTime($referral->expires_at)->format('F j, Y') }}</p>
                    @endif
                @endif
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
            @else
                @php($reward = $entry['item'])
                <p class="type-title-4 mt-3 text-text-1">{{ $reward->membership->customer->first_name }}'s reward: <span class="font-normal text-text-2">{{ $reward->reward_text_snapshot }}</span></p>
                @foreach (\App\Support\HoldReasons::sentencesForHold($reward->hold_rules, $business) as $sentence)
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
            @endif
        </div>
    @endforeach

    @if ($heldReferrals->isEmpty() && $heldRewards->isEmpty())
        <div class="mt-4 flex flex-col items-center rounded-lg border-[1.5px] border-dashed border-line-strong px-6 py-10 text-center">
            <span class="medallion"><x-icon name="clipboard-check" :size="24" /></span>
            <p class="type-title-3 mt-4 text-text-1">Nothing is waiting for review.</p>
            <p class="type-body-sm mt-1 text-text-2">Held items will appear here with the reason.</p>
        </div>
    @endif

    <h2 class="type-title-3 mt-10 text-text-1">Worth knowing</h2>
    <p class="type-body-sm mt-1 max-w-[68ch] text-text-2">Nothing here needs a decision. These lists are for your records.</p>

    <h3 class="type-title-4 mt-8 text-text-1">Redeemed at the counter</h3>
    <p class="type-body-sm mt-1 max-w-[68ch] text-text-2">When a customer shows their reward code, a team member scans it with their own phone and taps Mark as Redeemed. Nobody signs in to do it, so these redemptions show no name. That is the normal way redemption works.</p>

    @forelse ($unattributed as $referral)
        <div class="mt-3 flex items-center gap-3 rounded-lg border border-line bg-surface-0 px-4 py-3 shadow-e1">
            <p>{{ $referral->referredCustomer->first_name }}, referred by {{ $referral->referrerMembership->customer->first_name }}, redeemed {{ $business->localTime($referral->redeemed_at)->format('F j, Y') }}</p>
        </div>
    @empty
        <div class="mt-3 flex items-center gap-3 rounded-lg border-[1.5px] border-dashed border-line-strong px-5 py-4">
            <span class="medallion"><x-icon name="info" :size="24" /></span>
            <p class="type-body-sm text-text-2">Nothing here yet. Redemptions finished at the counter will be listed here.</p>
        </div>
    @endforelse

    <h3 class="type-title-4 mt-8 text-text-1">New phone numbers</h3>
    <p class="type-body-sm mt-1 max-w-[68ch] text-text-2">When a customer links a new phone number, their claims pause on their own for {{ $numberLinkCoolingDays }} days as a safety check. There is nothing for you to do. These customers linked a number in the last {{ $numberLinkCoolingDays }} days:</p>
    @forelse ($numberLinks as $event)
        <div class="mt-3 flex items-center gap-3 rounded-lg border border-line bg-surface-0 px-4 py-3 shadow-e1">
            <p>{{ $event->customer_name ?? 'A customer' }} linked a new number on {{ $business->localTime($event->created_at)->format('F j, Y') }}.</p>
        </div>
    @empty
        <div class="mt-3 flex items-center gap-3 rounded-lg border-[1.5px] border-dashed border-line-strong px-5 py-4">
            <span class="medallion"><x-icon name="info" :size="24" /></span>
            <p class="type-body-sm text-text-2">Nothing here yet. Customers who link a new number will be listed here while the {{ $numberLinkCoolingDays }}-day wait runs.</p>
        </div>
    @endforelse

    <h3 class="type-title-4 mt-8 text-text-1">Codes that could not be used</h3>
    <p class="type-body-sm mt-1 max-w-[68ch] text-text-2">A customer showed a code that did not work. The visit did not count.</p>
    @forelse ($rejections as $event)
        @php($rejectionDate = $business->localTime($event->created_at)->format('F j, Y'))
        <div class="mt-3 flex items-center gap-3 rounded-lg border border-line bg-surface-0 px-4 py-3 shadow-e1">
            @switch($event->metadata['reason'] ?? null)
                @case('expired_token')
                    <p>A code that had expired was shown on {{ $rejectionDate }}.</p>
                    @break
                @case('voided_token')
                    <p>A code that is no longer active was shown on {{ $rejectionDate }}.</p>
                    @break
                @case('denied_token')
                    <p>A code for a denied reward was shown on {{ $rejectionDate }}.</p>
                    @break
                @default
                    <p>A code that could not be used was shown on {{ $rejectionDate }}.</p>
            @endswitch
        </div>
    @empty
        <div class="mt-3 flex items-center gap-3 rounded-lg border-[1.5px] border-dashed border-line-strong px-5 py-4">
            <span class="medallion"><x-icon name="info" :size="24" /></span>
            <p class="type-body-sm text-text-2">Nothing here yet. If a customer shows a code that cannot be used, it will be listed here with the date.</p>
        </div>
    @endforelse
@endsection
