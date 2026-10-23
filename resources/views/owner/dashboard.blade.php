@extends('layouts.app')

@section('title', 'Overview')

@section('content')
    {{-- The Overview (spec Section 5.1, design amendment Section
         6.4): greeting, the one action card, the starter pack while
         rewards hold defaults, the action tiles, then the standing
         counters, the chart and top referrers, the Value panel
         (VAL-01 to VAL-03 verbatim), and the setup checklist
         (Section 5.8). In the zero state the standing sections stay
         hidden until the first enrollment exists (Section 6.4.1)
         and the tiles read an honest zero. --}}
    <h1 class="text-[28px] font-bold">Welcome to Easy Refer, {{ $business->name }}.</h1>
    @unless ($hasEnrollments)
        <p class="mt-1 text-text-2">Your program is live. The first step is getting your code where customers can see it.</p>
    @endunless

    @if ($business->isClosed())
        <div class="gate mt-4">
            <p>Your account is closed. You can still sign in to confirm claims and view your history until {{ $business->closed_at?->copy()->addDays(30)->format('M j, Y') }}. New enrollments and your QR code are off.</p>
        </div>
    @elseif ($business->status === 'disabled')
        <div class="gate mt-4">
            <p>Your account is paused by Easy Refer. New enrollments and your QR code are off. Visits already on their way can still finish.</p>
        </div>
    @endif

    @if (! $emailVerified)
        <div class="gate mt-4">
            <p><strong>Verify your email address.</strong> Please verify your email address to activate your QR code and billing. Check your inbox for the verification link.</p>
            <p><a href="{{ route('verification.notice') }}">Verify your email</a></p>
        </div>
    @endif

    <div class="card mt-5">
        <div class="flex items-start gap-4">
            <img src="{{ route('owner.qr.image') }}" alt="" width="72" height="72" class="shrink-0 rounded-md border border-line">
            <div>
                @if ($firstCustomer)
                    {{-- The first-enrollment transition (design
                         6.4.1 item 8): the card's headline and
                         Primary move to the customers until the
                         first verified visit lands. --}}
                    <h2 class="text-xl font-semibold">Your first customer just joined</h2>
                    <p class="mt-1 text-text-2">Posters at the register get scanned. Posters in a drawer do not.</p>
                    <a class="btn btn-primary mt-3" href="{{ route('customers.index') }}">See your customers</a>
                @else
                    <h2 class="text-xl font-semibold">Print your QR code</h2>
                    <p class="mt-1 text-text-2">Posters at the register get scanned. Posters in a drawer do not.</p>
                    <a class="btn btn-primary mt-3" href="{{ route('owner.qr') }}">Print your QR code</a>
                @endif
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
                        {{-- Choosing a pack PREFILLS the Settings
                             Rewards fields and lands the owner
                             there with the values editable (spec
                             Section 5.5, design 6.4.1 item 3);
                             nothing is saved until that form is
                             submitted. --}}
                        <p class="mt-2">
                            <a class="btn btn-secondary" href="{{ route('settings.rewards', ['pack_friend' => $pack['friend'], 'pack_referrer' => $pack['referrer']]) }}">Use this pack</a>
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- The setup checklist sits directly under the action
         cards (design 6.4.1 item 4): it is the map, never
         outranking the action card, and never buried below the
         panels it points at. --}}
    {{-- Block form for this computation, never the inline
         one-line form: the template also carries the chart's
         PHP block below, and Blade pairs PHP block markers in
         order, so an inline marker above a block marker
         swallows everything between them (found in the Sprint
         3 fix wave, when the checklist moved above the chart).
         Keep literal directive names out of this comment for
         the same reason. --}}
    @php
        $checklistDone = collect($checklist)->filter()->count();
    @endphp
    @if ($checklistDone < 4 && ! $checklistDismissed)
        <div class="card mt-4">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold">Setup checklist</h2>
                <form method="POST" action="{{ route('dashboard.checklist.dismiss') }}">
                    @csrf
                    <button type="submit" class="font-semibold text-brand-700 hover:underline">Dismiss</button>
                </form>
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
                        <span aria-hidden="true">○</span> <a class="font-semibold text-brand-700 hover:underline" href="{{ route('settings.rewards') }}">Set your rewards</a>
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

    {{-- Today's tiles (design 6.4.1 item 5, 6.5): two columns
         below sm, and they render honest zeros from the first
         day; the rewards tile links to the Rewards list. The
         review tile alone stays hidden at zero (spec 5.1). --}}
    <div class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card tile">
            <p class="tile-label">Scans today</p>
            <p class="tile-value">{{ $tiles['scans_today'] }}</p>
        </div>
        <div class="card tile">
            <p class="tile-label">Completed referrals today</p>
            <p class="tile-value">{{ $tiles['referrals_today'] }}</p>
            <p class="tile-delta">{{ $tiles['referrals_yesterday'] }} yesterday</p>
        </div>
        <a class="card tile block" href="{{ route('rewards.index') }}">
            <p class="tile-label">Rewards waiting to be claimed</p>
            <p class="tile-value">{{ $tiles['rewards_waiting'] }}</p>
        </a>
        @if ($tiles['review_waiting'] > 0)
            <a class="card tile block" href="{{ route('reviews') }}">
                <p class="tile-label">Waiting for your review</p>
                <p class="tile-value">{{ $tiles['review_waiting'] }}</p>
            </a>
        @endif
    </div>

    @if ($hasEnrollments)
        {{-- Standing counters (spec Section 5.1): all time, with
             pending as its own tile, never merged into a total.
             Labels are DASH-01 and OVR-01. --}}
        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card tile">
                <p class="tile-label">Referrals</p>
                <p class="tile-value">{{ $counters['completed_total'] }}</p>
            </div>
            <div class="card tile">
                <p class="tile-label">Pending referrals</p>
                <p class="tile-value">{{ $counters['pending_total'] }}</p>
            </div>
            <div class="card tile">
                <p class="tile-label">New customers</p>
                <p class="tile-value">{{ $counters['new_customers'] }}</p>
                <p class="tile-delta">Last 5 days</p>
            </div>
            <div class="card tile">
                <p class="tile-label">Total customers</p>
                <p class="tile-value">{{ $counters['total_customers'] }}</p>
            </div>
            <div class="card tile">
                <p class="tile-label">Rewards issued</p>
                <p class="tile-value">{{ $counters['rewards_issued'] }}</p>
            </div>
            <div class="card tile">
                <p class="tile-label">Rewards claimed</p>
                <p class="tile-value">{{ $counters['rewards_claimed'] }}</p>
            </div>
            <div class="card tile">
                <p class="tile-label">Promotional messages sent</p>
                <p class="tile-value">{{ $counters['promo_sent'] }}</p>
            </div>
        </div>

        <div class="card mt-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-xl font-semibold">Cumulative referred customers</h2>
                <p class="flex gap-3 text-base">
                    <a href="{{ route('dashboard', ['range' => '7']) }}" @if ($chartRange === '7') aria-current="true" class="font-semibold text-brand-700" @else class="font-semibold text-brand-700 hover:underline" @endif>7 days</a>
                    <a href="{{ route('dashboard', ['range' => '30']) }}" @if ($chartRange === '30') aria-current="true" class="font-semibold text-brand-700" @else class="font-semibold text-brand-700 hover:underline" @endif>30 days</a>
                    <a href="{{ route('dashboard', ['range' => 'all']) }}" @if ($chartRange === 'all') aria-current="true" class="font-semibold text-brand-700" @else class="font-semibold text-brand-700 hover:underline" @endif>All time</a>
                </p>
            </div>
            @if ($chartTotal > 0)
                @php
                    $maxTotal = max(1, collect($chart)->max('total'));
                    $points = collect($chart)->map(function ($point, $index) use ($chart, $maxTotal) {
                        $x = count($chart) > 1 ? 8 + ($index * (704 / (count($chart) - 1))) : 360;
                        $y = 168 - (($point['total'] / $maxTotal) * 152);

                        return round($x, 1).','.round($y, 1);
                    })->implode(' ');
                @endphp
                <svg viewBox="0 0 720 190" class="mt-3 w-full" role="img" aria-label="Cumulative referred customers: {{ $chartTotal }} total">
                    <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="2.5" class="text-brand-700" />
                </svg>
                <p class="flex justify-between text-sm text-text-2">
                    <span>{{ $chart[0]['label'] }}</span>
                    <span>Customers</span>
                    <span>{{ $chart[count($chart) - 1]['label'] }}</span>
                </p>
                <details class="mt-2">
                    <summary class="cursor-pointer font-semibold text-brand-700">View as table</summary>
                    <table class="mt-2 w-full text-left text-base">
                        <thead>
                            <tr class="border-b border-line">
                                <th scope="col" class="py-2 pr-3 font-semibold">Date</th>
                                <th scope="col" class="py-2 font-semibold">Customers</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($chart as $point)
                                <tr class="border-b border-line">
                                    <td class="py-2 pr-3">{{ $point['label'] }}</td>
                                    <td class="py-2">{{ $point['total'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            @else
                <p class="mt-1 text-text-2">Your first visits will draw this line.</p>
            @endif
        </div>

        <div class="card mt-4">
            <h2 class="text-xl font-semibold">Weekly top referrers</h2>
            @if ($topReferrers->isNotEmpty())
                <table class="mt-2 w-full text-left text-base">
                    <thead>
                        <tr class="border-b border-line">
                            <th scope="col" class="py-2 pr-3 font-semibold">Name</th>
                            <th scope="col" class="py-2 pr-3 font-semibold">Referrals</th>
                            <th scope="col" class="py-2 pr-3 font-semibold">Registered</th>
                            <th scope="col" class="py-2 font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($topReferrers as $membership)
                            <tr class="border-b border-line">
                                <td class="py-2 pr-3">{{ $membership->customer->first_name }} {{ $membership->customer->last_name }}</td>
                                <td class="py-2 pr-3">{{ $membership->weekly_referrals }}</td>
                                <td class="py-2 pr-3">{{ $membership->created_at->format('M j, Y') }}</td>
                                <td class="py-2"><span class="pill {{ $membership->share_token_state === 'active' ? 'pill-success' : 'pill-neutral' }}">{{ $membership->share_token_state === 'active' ? 'Active' : 'Link off' }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="mt-1 text-text-2">No referrals yet. Share your QR code to get started.</p>
            @endif
        </div>
    @endif

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
            <p class="mt-2 text-text-2">Estimates use your average ticket of {{ '$'.number_format((int) $business->average_ticket_cents / 100, 2) }}. Update it any time in <a class="font-semibold text-brand-700 hover:underline" href="{{ route('settings.value') }}">Settings</a>.</p>
        @else
            <p class="mt-2 text-text-2">Estimates use your average ticket. Update it any time in <a class="font-semibold text-brand-700 hover:underline" href="{{ route('settings.value') }}">Settings</a>.</p>
        @endif
    </div>

@endsection
