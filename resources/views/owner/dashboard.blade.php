@extends('layouts.app')

@section('title', 'Overview')

@section('content')
    {{-- The Overview (spec Section 5.1, design amendment Section
         6.4): greeting, the one action card, the starter pack while
         rewards hold defaults, the setup checklist (Section 5.8)
         while the poster step is open, the action tiles, then the
         standing counters, the chart and top referrers, and the
         Value panel (VAL-01 to VAL-03 verbatim). DG-2 (release
         08b) gates the setup furniture on the checklist's own
         state: once the poster is printed the QR card collapses
         to a slim link row and the checklist moves below the
         tiles. In the zero state the standing sections stay
         hidden until the first enrollment exists (Section 6.4.1)
         and the tiles read an honest zero. Post-r26 (pack
         01-overview, OV-1 to OV-6): the standing state
         (enrollments exist, the poster step is done, and every
         checklist step is done) recomposes today-led: a date
         and today summary line under the greeting, a needs-you
         strip, the tiles, the chart with the Value panel
         directly under it, the counters under their "All time"
         heading, top referrers as rows, and the finished
         checklist as one slim row. While any step is open the
         legacy order below holds byte for byte. --}}
    @php
        // The standing gate reads only values this render
        // already carries (the checklist array and the
        // enrollment flag): no new queries (query budget).
        // ON-SET-2: "every step done" means every REQUIRED
        // step (SetupChecklist::progress, the shared count);
        // the optional review link never blocks standing.
        [$checklistRequiredDone, $checklistTotal] = \App\Support\SetupChecklist::progress($checklist);
        $isStanding = $hasEnrollments
            && (bool) ($checklist['poster_downloaded'] ?? false)
            && $checklistRequiredDone >= $checklistTotal;
    @endphp
    <h1 class="type-title-2 text-text-1">Welcome to Easy Refer, {{ $business->name }}.</h1>
    @if ($isStanding)
        {{-- OV-2 (Amendment 29 OV-1): the date and today
             summary line. Its counts are the Today tiles' own
             values from this same render, so the line and the
             tiles can never disagree. --}}
        <p class="type-body-sm mt-1 text-text-2" data-ov-summary>{{ now($business->timezone ?: 'America/New_York')->format('l, F j') }}. @if ($tiles['scans_today'] === 0 && $tiles['referrals_today'] === 0)No scans yet today.@else{{ $tiles['scans_today'] }} scans today, {{ $tiles['referrals_today'] }} referrals completed.@endif</p>
    @endif
    @unless ($hasEnrollments)
        {{-- G8 (coherence Phase 2): the liveness claim is
             gated on the flow itself (DASH-22) — the sentence
             renders only while the code a customer scans
             actually works. --}}
        @if ($business->flowIsActive())
            <p class="type-body-sm mt-1 text-text-2">Your program is live. The first step is getting your code where customers can see it.</p>
        @else
            <p class="type-body-sm mt-1 text-text-2">Your program is not live yet. Finish setup and your code starts working.</p>
        @endif
    @endunless

    @if ($business->isClosed())
        {{-- DASH-13 closed banner (FE-CANCEL-01 truth item):
             the reward-deadline sentence branches by state. Past
             day 30 the claim window has ended and the banner
             says so; rendering the deadline form there would
             assert a live window that no longer exists.
             [M4-DASH-01] (Amendment 71 addendum, DASH-13 fix):
             inside the window, the exact-date sentence branches
             when a live reward's own use-by date falls before
             the closure date it names, per the Section 3.1
             pattern (the [M4-ACCT-04] twin on the account page);
             otherwise the original form is true for every reward
             it covers and renders unchanged. --}}
        <div class="gate mt-4">
            @if ($business->closureWindowEnded())
                <p>Your program has ended. You can still sign in and view your history. The 30 days for claiming rewards ended on {{ $business->localTime($business->closed_at->copy()->addDays(30))->format('M j, Y') }}. New enrollments and your QR code are off.</p>
            @else
                <p>Your program has ended. You can still sign in and view your history. Rewards your customers earned stay claimable until {{ $business->localTime($business->closed_at->copy()->addDays(30))->format('M j, Y') }}{{ ($closedRewardOwnDateInsideWindow ?? false) ? ', or until their own use by date if that comes first' : '' }}. New enrollments and your QR code are off.</p>
            @endif
        </div>
    @elseif ($business->status === 'disabled')
        <div class="gate mt-4">
            <p>Your account is paused by Easy Refer. New enrollments and your QR code are off. Visits already on their way can still finish.</p>
        </div>
    @endif

    @if (! $emailVerified)
        <div class="gate mt-4">
            <p><strong>Verify your email address.</strong> Verify it so your receipts and password resets reach you. Check your inbox for the verification link.</p>
            <p><a href="{{ route('verification.notice') }}">Verify your email</a></p>
        </div>
    @endif

    {{-- SV-03 (Train 2): a failed payment gates the dashboard
         too, in the same words as the billing banner and the
         Action Center row (one shared partial, BILL-PD-01).
         The raw status word never renders. --}}
    @if ($business->billing_status === 'past_due')
        <div class="gate mt-4">
            @include('partials.billing-past-due', ['cardHref' => route('settings.billing').'#card'])
        </div>
    @endif

    {{-- Allowance state visibility (activation redesign,
         design pass Section 6.2): the countdown to the pause
         is visible where the owner naturally looks, with its
         action beside it — the soft line at 5..2 left
         [ACT-VIS-01], the warning at exactly 1 [ACT-VIS-02],
         the paused state at 0 [ACT-VIS-03]. Cardless states
         only; a carded business has done the thing the lines
         ask for. One query in cardless states (the gate's own
         count, memoized on the instance). --}}
    @if (! $business->hasCardOnFile() && $business->isActive())
        @if ($business->allowanceLeft() >= 2)
            <div class="card mt-4">
                <p class="type-body text-text-1">Your code is on. You have {{ $business->allowanceLeft() }} of your 5 free visits left. Add a card any time so it stays on after that.</p>
                <p class="mt-3"><a class="btn btn-secondary" href="{{ route('settings.billing') }}#card">Add a card</a></p>
            </div>
        @elseif ($business->allowanceLeft() === 1)
            <div class="card mt-4">
                <p class="type-body text-text-1">One free visit left. After it, your code pauses until you add a card. Visits you have already earned still finish as usual.</p>
                <p class="mt-3"><a class="btn btn-secondary" href="{{ route('settings.billing') }}#card">Add a card</a></p>
            </div>
        @elseif ($business->flowBlockReason() === 'allowance_exhausted')
            <div class="card mt-4">
                <p class="type-body text-text-1">Your 5 free visits are used up, so your code is paused. Add a card to turn it back on. Nothing is charged when you add your card.</p>
                <p class="mt-3"><a class="btn btn-primary" href="{{ route('settings.billing') }}#card">Add a card</a></p>
            </div>
        @endif
    @endif

    {{-- DG-2 (release 08b): the checklist's done count and the
         poster step's state are computed once, ahead of the QR
         card, because both the card's collapsed form and the
         checklist's position key on them. --}}
    @php
        // ON-SET-2: the done count below (and the OV-5
        // setup-complete row keyed on it) is the REQUIRED
        // count from the one shared definition.
        [$checklistDone, $checklistTotal] = \App\Support\SetupChecklist::progress($checklist);
        $printDone = (bool) ($checklist['poster_downloaded'] ?? false);
        // P4 (OB-U6): the flow's state for the resume banner and
        // the checklist's re-pointed rows, read from this same
        // checklist computation (no second pass, no new queries).
        $flowStates = \App\Support\Onboarding::states($business, auth()->user(), $checklist);
        $flowDone = \App\Support\Onboarding::doneCount($flowStates);
        $flowOpenStep = \App\Support\Onboarding::firstOpenStep($flowStates);
        $onboardingOpen = $business->onboarding_completed_at === null && $flowOpenStep !== null;
    @endphp

    {{-- The onboarding resume banner (P4, OB-U6, pack 5.3):
         while the flow is open and the checklist is not
         dismissed, the dashboard's first setup surface is the
         way back into the flow. Dismissing the checklist hides
         it too: one dismissal domain, like the card and the
         Action Center row. --}}
    @if ($onboardingOpen && ! $checklistDismissed)
        <div class="card mt-4">
            <p class="type-body text-text-1">You're partway through setup, {{ $flowDone }} of {{ \App\Support\Onboarding::requiredTotal() }} required steps done.</p>
            <p class="mt-3"><a class="btn btn-primary" href="{{ route('onboarding.step', $flowOpenStep) }}">Pick up where you left off</a></p>
        </div>
    @endif
    @if ($printDone && ! $firstCustomer)
        {{-- DG-2 (release 08b): once the poster is printed the
             card's standing work is done, so it collapses to a
             slim link row (thumbnail, the QR page link, the
             poster rows) instead of repeating the full action
             card on every visit. The first-customer moment is
             exempt: that card is a value moment, not setup
             furniture, and keeps its full form. --}}
        <div class="card mt-5">
            <div class="flex items-center gap-3">
                <img src="{{ route('owner.qr.image') }}" alt="" width="44" height="44" class="shrink-0 rounded-lg border border-line">
                <a class="inline-flex min-h-12 items-center font-semibold text-link hover:underline" href="{{ route('owner.qr') }}">Print your QR code</a>
            </div>
            @include('partials.dashboard-qr-rows')
        </div>
    @else
    <div class="card mt-5">
        <div class="flex items-start gap-4">
            <img src="{{ route('owner.qr.image') }}" alt="" width="88" height="88" class="shrink-0 rounded-lg border border-line">
            <div>
                @if ($firstCustomer)
                    {{-- The first-enrollment transition (design
                         6.4.1 item 8): the card's headline and
                         Primary move to the customers until the
                         first visit counts. --}}
                    <h2 class="type-title-3 text-text-1">Your first customer just joined</h2>
                    <p class="type-body-sm mt-1 text-text-2">Posters at the register get scanned. Posters in a drawer do not.</p>
                    <a class="btn btn-primary mt-3" href="{{ route('customers.index') }}">See your customers</a>
                @else
                    <h2 class="type-title-3 text-text-1">Print your QR code</h2>
                    <p class="type-body-sm mt-1 text-text-2">Posters at the register get scanned. Posters in a drawer do not.</p>
                    <a class="btn btn-primary mt-3" href="{{ route('owner.qr') }}">Print your QR code</a>
                @endif
            </div>
        </div>
        @include('partials.dashboard-qr-rows')
    </div>
    @endif

    @if ($showStarterPack)
        {{-- PACK-00, the starter pack card (spec Section 5.5,
             design 6.4.1 item 3): the business type's three packs
             as selectable rows, each with its typical-cost note
             labeled as guidance. Choosing a pack writes the pair
             through the one reward-text path; both rewards stay
             editable afterwards. Every string is the inventory's. --}}
        <div class="card mt-4" id="starter-pack">
            <h2 class="type-title-3 text-text-1">Start from a ready-made pack</h2>
            <p class="type-body-sm mt-1 text-text-2">You can edit the reward after picking.</p>

            <div class="mt-3 space-y-3">
                @foreach ($packs as $pack)
                    <div class="well border border-line transition-colors hover:border-line-strong">
                        <p>{{ $pack['reward'] }}</p>
                        <p class="type-body-sm mt-1 text-text-2">Typical cost: {{ $pack['cost'] }}</p>
                        {{-- Choosing a pack PREFILLS the Settings
                             Rewards fields and lands the owner
                             there with the values editable (spec
                             Section 5.5, design 6.4.1 item 3);
                             nothing is saved until that form is
                             submitted. --}}
                        <p class="mt-2">
                            <a class="btn btn-secondary" href="{{ route('settings.rewards', ['pack_reward' => $pack['reward']]) }}">Use this pack</a>
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- The setup checklist's first position (design 6.4.1
         item 4): directly under the action cards while the
         poster step is open, so mid-setup it still leads.
         Once the poster is printed, DG-2 (release 08b) moves
         it below the tiles instead (the second include, after
         the tiles grid): finished setup furniture stops
         outranking the day's numbers. The done-count
         computation lives above the QR card in block form,
         never the inline one-line form: the template also
         carries the chart's PHP block below, and Blade pairs
         PHP block markers in order, so an inline marker above
         a block marker swallows everything between them
         (found in the Sprint 3 fix wave, when the checklist
         moved above the chart). Keep literal directive names
         out of this comment for the same reason. --}}
    @unless ($printDone)
        @include('partials.dashboard-checklist')
    @endunless

    @if ($isStanding && ($tiles['review_waiting'] > 0 || $tiles['rewards_waiting'] > 0))
        {{-- OV-3 needs-you strip (standing state only): one
             tappable row per open need, counts only, each a link
             to its queue. A summary of the Action Center, never
             a second queue: no actions of its own. The labels
             are the Today tiles' shipped words. --}}
        <div class="mt-4 space-y-2" data-ov-needs>
            @if ($tiles['review_waiting'] > 0)
                <a class="card flex items-center justify-between gap-3" href="{{ route('reviews') }}">
                    <span class="font-semibold text-text-1">Waiting for your review</span>
                    <span class="tile-value-sm">{{ $tiles['review_waiting'] }}</span>
                </a>
            @endif
            @if ($tiles['rewards_waiting'] > 0)
                <a class="card flex items-center justify-between gap-3" href="{{ route('rewards.index') }}">
                    <span class="font-semibold text-text-1">Rewards waiting to be claimed</span>
                    <span class="tile-value-sm">{{ $tiles['rewards_waiting'] }}</span>
                </a>
            @endif
        </div>
    @endif

    {{-- Today's tiles (design 6.4.1 item 5, 6.5): two columns
         below sm, and they render honest zeros from the first
         day; the rewards tile links to the Rewards list. The
         review tile alone stays hidden at zero (spec 5.1). --}}
    <div class="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-line bg-line shadow-e1 lg:grid-cols-4">
        <div class="tile bg-surface-0 p-5">
            <p class="tile-label">Scans today</p>
            <p class="tile-value">{{ $tiles['scans_today'] }}</p>
        </div>
        <div class="tile bg-surface-0 p-5">
            <p class="tile-label">Completed referrals today</p>
            <p class="tile-value">{{ $tiles['referrals_today'] }}</p>
            <p class="tile-delta">{{ $tiles['referrals_yesterday'] }} yesterday</p>
        </div>
        {{-- With no review tile the grid holds three tiles;
             the last one spans the remaining columns so no
             empty cell renders (NEW-3). --}}
        <a class="tile block bg-surface-0 p-5 hover:bg-surface-1 {{ $tiles['review_waiting'] > 0 ? '' : 'col-span-2' }}" href="{{ route('rewards.index') }}">
            <p class="tile-label">Rewards waiting to be claimed</p>
            <p class="tile-value">{{ $tiles['rewards_waiting'] }}</p>
        </a>
        @if ($tiles['review_waiting'] > 0)
            <a class="tile block bg-surface-0 p-5 hover:bg-surface-1" href="{{ route('reviews') }}">
                <p class="tile-label">Waiting for your review</p>
                <p class="tile-value">{{ $tiles['review_waiting'] }}</p>
            </a>
        @endif
    </div>

    {{-- The checklist's second position (DG-2): below the
         tiles, reached once the poster step is done. In the
         standing state every step is done, so the include
         renders nothing and the slim completion row (OV-5)
         closes the standing sections instead. --}}
    @if ($printDone)
        @include('partials.dashboard-checklist')
    @endif

    @if ($hasEnrollments && $isStanding)
        {{-- OV-3 standing order: the chart, the Value panel
             directly under it as its money footnote, the
             counters under their All time heading, and top
             referrers last. --}}
        @include('partials.dashboard-chart')
        @include('partials.dashboard-value')
        @include('partials.dashboard-counters')
        @include('partials.dashboard-top-referrers')
    @elseif ($hasEnrollments)
        {{-- Legacy order for the setup states: counters, the
             chart, top referrers, and the Value panel last
             (below, outside this block, as today). --}}
        @include('partials.dashboard-counters')
        @include('partials.dashboard-chart')
        @include('partials.dashboard-top-referrers')
    @endif

    @if ($checklistDone >= $checklistTotal && ! $checklistDismissed)
        {{-- OV-5 (Amendment 29 OV-3): the finished checklist is
             one slim row, not the full card. The dismiss action
             is the checklist's own; the Settings reopen path is
             unchanged. --}}
        <div class="card mt-4" data-ov-setup-complete>
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-5 w-5 flex-none items-center justify-center rounded-full bg-success-fill text-success-strong"><x-icon name="check" :size="16" /></span>
                    <div>
                        <p class="font-semibold text-text-1">Setup complete.</p>
                        <p class="type-body-sm text-text-2">You can reopen the checklist in Settings.</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('dashboard.checklist.dismiss') }}" data-loading>
                    @csrf
                    <button type="submit" class="btn btn-ghost btn-sm">Dismiss</button>
                </form>
            </div>
        </div>
    @endif

    @unless ($isStanding)
        @include('partials.dashboard-value')
    @endunless

@endsection
