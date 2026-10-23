@extends('layouts.app')

@section('title', 'Overview')

@section('content')
    {{-- The Overview (spec Section 5, design amendment Section
         6.4.1): greeting, the one action card, the starter pack
         while rewards hold defaults, then time (the tiles), money
         (the Value panel, VAL-01 to VAL-03 verbatim), and setup
         (the AUTH-SEC-03 checklist). In the zero state the tiles
         read an honest zero and the standing counters stay hidden
         until the first enrollment exists. --}}
    <h1 class="text-[28px] font-bold">Welcome to Easy Refer, {{ $business->name }}.</h1>
    @unless ($hasEnrollments)
        <p class="mt-1 text-text-2">Your program is live. The first step is getting your code where customers can see it.</p>
    @endunless

    @if (! $emailVerified)
        <div class="gate mt-4">
            <p><strong>Verify your email address.</strong> Your public QR code and billing stay off until the email on this account is verified.</p>
            <p><a href="{{ route('verification.notice') }}">Verify your email</a></p>
        </div>
    @endif

    <div class="card mt-5">
        <div class="flex items-start gap-4">
            <img src="{{ route('owner.qr.image') }}" alt="" width="72" height="72" class="shrink-0 rounded-md border border-line">
            <div>
                <h2 class="text-xl font-semibold">Print your QR code</h2>
                <p class="mt-1 text-text-2">Posters at the register get scanned. Posters in a drawer do not.</p>
                <a class="btn btn-primary mt-3" href="{{ route('owner.qr') }}">Print your QR code</a>
                <p class="mt-3 flex flex-wrap gap-x-4 gap-y-1">
                    <a class="font-semibold text-brand-700 hover:underline" href="{{ route('owner.qr') }}#posters">Download the on-screen poster</a>
                    <a class="font-semibold text-brand-700 hover:underline" href="{{ route('owner.qr') }}#how-redemption-works">See how redemption works</a>
                </p>
            </div>
        </div>
    </div>

    @if ($showStarterPack)
        {{-- PACK-00, the starter pack card (spec Section 5.5,
             design 6.4.1 item 3): the business type's three packs
             as selectable rows, each with its typical-cost note
             labeled as guidance. Choosing a pack writes the pair
             through the one reward-text path; both rewards stay
             editable afterwards. Every string is the inventory's. --}}
        <div class="card mt-4" id="starter-pack">
            <h2 class="text-xl font-semibold">Start from a pack owners like you use</h2>
            <p class="mt-1 text-text-2">You can edit both rewards after picking.</p>

            <div class="mt-3 space-y-3">
                @foreach ($packs as $pack)
                    <div class="rounded-md border border-line p-4">
                        <p><span class="font-semibold">For the friend:</span> {{ $pack['friend'] }}</p>
                        <p class="mt-1"><span class="font-semibold">For the referrer:</span> {{ $pack['referrer'] }}</p>
                        <p class="mt-1 text-text-2">Typical cost: {{ $pack['cost'] }}</p>
                        <form method="POST" action="{{ route('owner.reward-texts') }}" class="mt-2">
                            @csrf
                            <input type="hidden" name="friend_reward_text" value="{{ $pack['friend'] }}">
                            <input type="hidden" name="referrer_reward_text" value="{{ $pack['referrer'] }}">
                            <button type="submit" class="btn btn-secondary">Use this pack</button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card tile">
            <p class="tile-label">Scans today</p>
            <p class="tile-value">{{ $tiles['scans_today'] }}</p>
        </div>
        <div class="card tile">
            <p class="tile-label">Completed referrals today</p>
            <p class="tile-value">{{ $tiles['referrals_today'] }}</p>
            <p class="tile-delta">{{ $tiles['referrals_yesterday'] }} yesterday</p>
        </div>
        @if ($hasEnrollments)
            <div class="card tile">
                <p class="tile-label">Rewards waiting to be claimed</p>
                <p class="tile-value">{{ $tiles['rewards_waiting'] }}</p>
            </div>
            @if ($tiles['review_waiting'] > 0)
                <a class="card tile block" href="{{ route('reviews') }}">
                    <p class="tile-label">Waiting for your review</p>
                    <p class="tile-value">{{ $tiles['review_waiting'] }}</p>
                </a>
            @endif
        @endif
    </div>

    <div class="card mt-4">
        <h2 class="text-xl font-semibold">What Easy Refer brought you</h2>
        @if ($value['verified_visits'] > 0)
            <ul class="mt-2 space-y-1 text-base">
                <li>{{ $value['verified_visits'] }} verified {{ $value['verified_visits'] === 1 ? 'visit' : 'visits' }} this month</li>
                <li>About {{ '$'.number_format($value['estimated_sales_cents'] / 100, 2) }} in first-visit sales (estimate)</li>
                <li>Fees so far this month: {{ '$'.number_format($value['fees_cents'] / 100, 2) }}</li>
            </ul>
            @if ($valueAllTime['verified_visits'] > $value['verified_visits'])
                <p class="mt-2 text-base">All time: {{ $valueAllTime['verified_visits'] }} verified visits, about {{ '$'.number_format($valueAllTime['estimated_sales_cents'] / 100, 2) }} in first-visit sales (estimate)</p>
            @endif
        @else
            <p class="mt-1 text-text-2">After your first verified visit, this panel shows what Easy Refer brought you, in visits and in estimated sales.</p>
        @endif
        @if ((int) $business->average_ticket_cents > 0)
            <p class="mt-2 text-text-2">Estimates use your average ticket of {{ '$'.number_format((int) $business->average_ticket_cents / 100, 2) }}. Update it any time in <a class="font-semibold text-brand-700 hover:underline" href="{{ route('security') }}">Settings</a>.</p>
        @else
            <p class="mt-2 text-text-2">Estimates use your average ticket. Update it any time in <a class="font-semibold text-brand-700 hover:underline" href="{{ route('security') }}">Settings</a>.</p>
        @endif
    </div>

    @php($checklistDone = collect($checklist)->filter()->count())
    @if ($checklistDone < 4)
        <div class="card mt-4" x-data="{ dismissed: false }" x-show="! dismissed">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold">Setup checklist</h2>
                <button type="button" class="font-semibold text-brand-700 hover:underline" @click="dismissed = true">Dismiss</button>
            </div>
            <p class="mt-1 text-text-2">{{ $checklistDone }} of 4 done</p>
            <ul class="mt-2 space-y-2">
                <li>
                    @if ($checklist['email_verified'])
                        <span aria-hidden="true">✓</span> Verify your email
                    @else
                        <span aria-hidden="true">○</span> <a class="font-semibold text-brand-700 hover:underline" href="{{ route('verification.notice') }}">Verify your email</a>
                    @endif
                </li>
                <li>
                    @if ($checklist['reward_text'])
                        <span aria-hidden="true">✓</span> Set your rewards
                    @else
                        <span aria-hidden="true">○</span> <a class="font-semibold text-brand-700 hover:underline" href="#starter-pack">Set your rewards</a>
                    @endif
                </li>
                <li>
                    @if ($checklist['poster_downloaded'])
                        <span aria-hidden="true">✓</span> Print your QR code
                    @else
                        <span aria-hidden="true">○</span> <a class="font-semibold text-brand-700 hover:underline" href="{{ route('owner.qr') }}">Print your QR code</a>
                    @endif
                </li>
                <li>
                    @if ($checklist['card_on_file'])
                        <span aria-hidden="true">✓</span> Add a card (required to activate your QR code)
                    @else
                        <span aria-hidden="true">○</span> Add a card (required to activate your QR code)
                    @endif
                </li>
            </ul>
        </div>
    @endif
@endsection
