@extends('layouts.app')

@section('title', 'Account')


@section('content')
    <h1 class="type-title-2 text-text-1">Account</h1>

    <p class="type-body mt-2 max-w-[68ch] text-text-2">Who signs in to this account, and how to end your program.</p>

    <div class="mt-6 lg:flex lg:items-start lg:gap-8">
        <div class="lg:w-[220px] lg:flex-none">
            @include('owner.settings.nav')
        </div>
        <div class="mt-6 min-w-0 flex-1 lg:mt-0">

    {{-- R2 SET-ACCOUNT (design section 8): identity first.
         The email-state partial moves in unchanged (address,
         verified pill, resend and change actions as shipped). --}}
    <div class="panel">
        <h2 class="type-title-3 text-text-1">{{ $business->name }}</h2>
        <p class="type-body-sm mt-1 text-text-2">Business account · signed in as the owner</p>
        <div class="mt-3">
            @include('partials.account-email-state', ['user' => $user])
        </div>
    </div>

    <div class="panel mt-4">
        <h2 class="type-title-3 text-error-text">End my program</h2>
        @if ($business->isClosed())
            {{-- E-08 / E-08b (FE-CANCEL-01): the closed line
                 branches by state. Inside the 30-day window the
                 claim deadline is a live promise; after it, the
                 deadline sentence is replaced by its ended form,
                 so no surface ever asserts a claim window that
                 has run out (the SA-01 state-truth rule). --}}
            @if ($business->closureWindowEnded())
                <p class="mt-1 text-text-2">Your program ended on {{ $business->localTime($business->closed_at)->format('M j, Y') }}. You can still sign in and view your history. The 30 days for claiming rewards ended on {{ $business->localTime($business->closed_at->copy()->addDays(30))->format('M j, Y') }}. Rewards from the program can no longer be claimed.</p>
            @else
                {{-- [M4-ACCT-04] (Amendment 71 addendum, D2 fix):
                     the exact-date line branches when a live
                     reward's own use-by date falls before the
                     closure date it names, per the Section 3.1
                     pattern; otherwise the original [E-08] form
                     is true for every reward it covers and
                     renders unchanged. --}}
                <p class="mt-1 text-text-2">Your program ended on {{ $business->localTime($business->closed_at)->format('M j, Y') }}. You can still sign in and view your history. Rewards your customers earned stay claimable until {{ $business->localTime($business->closed_at->copy()->addDays(30))->format('M j, Y') }}{{ ($closedRewardOwnDateInsideWindow ?? false) ? ', or until their own use by date if that comes first' : '' }}.</p>
            @endif
            {{-- E-09: the reactivation answer, true in every
                 closed sub-state (manual path, allowance not
                 restored, card required before the code turns
                 on). --}}
            <p class="mt-1 text-text-2">Ended programs do not restart on their own. If you want to run referrals again, contact us and we will set you up. Free visits are once per business, so a restarted program needs a card on file before the code turns on.</p>
        @else
            {{-- E-02: the lede, true unconditionally at the tap. --}}
            <p class="mt-1">Ending your program turns your QR code off right away. No new visits or texts are charged after you end. Rewards your customers already earned stay good for 30 days after you end, or until their own use by date if that comes first. Your account stays open so you can sign in and see your history. Nothing is deleted.</p>
            {{-- E-03 / E-03b / E-03c: the final-bill figure from
                 the settlement walk's forecast in this same
                 request, branched by its band so the charge
                 promise is only made when a charge can happen. --}}
            @php($forecastTotal = (int) ($forecastPreview['total_cents'] ?? 0))
            @if ($forecastTotal >= 100)
                <p class="mt-1">Your final bill is {{ '$'.number_format($forecastTotal / 100, 2) }}: {{ $forecastPreview['billable_events'] }} visits that counted ({{ '$'.number_format(((int) $forecastPreview['visit_cents']) / 100, 2) }}) and {{ '$'.number_format(((int) $forecastPreview['text_cents']) / 100, 2) }} in texts. We charge the card on file today. If the charge does not go through, your program still ends and we will try the card again once a month until it is paid.</p>
            @elseif ($forecastTotal > 0)
                <p class="mt-1">You owe {{ '$'.number_format($forecastTotal / 100, 2) }} in texts. That is under the $1 card minimum, so we will not charge it.</p>
            @else
                <p class="mt-1">You owe nothing right now. Ending today means no final bill.</p>
            @endif
            {{-- E-04: claimed-but-unvisited friends, named with
                 their real count, only when the count is above
                 zero. --}}
            @if (($pendingReferralsCount ?? 0) > 0)
                <p class="mt-1">{{ $pendingReferralsCount }} friends have claimed a reward and have not visited yet. Their rewards end when the program ends, because the visit has not happened.</p>
            @endif
            {{-- E-12 (Amendment 70): rewards still outstanding,
                 the count design 1.1 promised at decision time.
                 Absent at zero, like every sibling line on this
                 panel; the fate sentence is E-05's tail, true for
                 every outstanding reward (held ones included:
                 ending does not void them, and a release stays
                 claimable inside the same 30 days).
                 [M4-E12-01] (Amendment 71 addendum): the duration
                 branches when a counted reward's own use-by date
                 falls inside the prospective 30-day window, per
                 the [M4-ACCT-04] suffix pattern; otherwise the
                 original Amendment 70 sentence is true for every
                 reward it covers and renders unchanged. --}}
            @if (($outstandingRewardsCount ?? 0) > 0)
                <p class="mt-1">You have {{ $outstandingRewardsCount }} {{ $outstandingRewardsCount === 1 ? 'reward' : 'rewards' }} still outstanding. {{ $outstandingRewardsCount === 1 ? 'It stays' : 'They stay' }} good until 30 days after you end{{ ($outstandingRewardOwnDateInsideWindow ?? false) ? ', or until '.($outstandingRewardsCount === 1 ? 'its' : 'their').' own use by date if that comes first' : '' }}. Any that are not claimed by then run out.</p>
            @endif
            {{-- E-05: rewards still vesting, with the day-30
                 truth (a reward vesting after the window ends
                 is never claimable; this line promises each
                 reward its window once available, nothing more). --}}
            @if (($vestingRewardsCount ?? 0) > 0)
                <p class="mt-1">Referrer rewards still building up become claimable on their usual date and stay good until 30 days after you end, or their own use by date if that comes first. Any that are not claimed by then run out.</p>
            @endif
            {{-- E-06: unused free visits die with the program. --}}
            @if (($allowanceRemaining ?? 0) > 0)
                <p class="mt-1">You have {{ $allowanceRemaining }} free visits left. They end with the program and cannot be used later.</p>
            @endif
            {{-- E-07: a past-due episode stays owed; its amount
                 is the episode record's own figure. --}}
            @if (($pastDueEpisode ?? null) !== null)
                <p class="mt-1">You also have a past-due amount of {{ '$'.number_format($pastDueEpisode['amount_cents'] / 100, 2) }} from {{ $pastDueEpisode['month'] }}. Ending does not remove it. It stays owed and we keep trying the card once a month.</p>
            @endif
            {{-- E-HELD (extends Amendment 38's held line): held
                 referrals AND held rewards, named by kind.
                 [M4-ACCT-03] (Amendment 71 addendum, D2 fix): the
                 tail branches when a held reward's own use-by
                 date falls inside the 30-day window the tail
                 names, per the Section 3.1 pattern; otherwise
                 the original tail is true for every reward it
                 covers and renders unchanged. --}}
            @php($heldItemsCount = ($heldReferralsCount ?? 0) + ($heldRewardsCount ?? 0))
            @if ($heldItemsCount > 0)
                <p class="mt-1">You have {{ $heldItemsCount }} {{ $heldItemsCount === 1 ? 'item' : 'items' }} waiting for your review: {{ $heldReferralsCount ?? 0 }} {{ ($heldReferralsCount ?? 0) === 1 ? 'referral' : 'referrals' }} and {{ $heldRewardsCount ?? 0 }} {{ ($heldRewardsCount ?? 0) === 1 ? 'reward' : 'rewards' }}. You can still release or deny them after you end, from the review queue. Anything you release stays claimable until 30 days after you end{{ ($heldRewardOwnDateInsideWindow ?? false) ? ', or until its own use by date if that comes first' : '' }}.</p>
            @endif
            {{-- The confirm stays disabled until the gate is met:
                 the checkbox is the gate, and the disabled state
                 is visible in the markup, not only in native
                 validation. The reason chips are optional and
                 never gate the end (design 5.4). --}}
            <form method="POST" action="{{ route('settings.account.close') }}" class="mt-4" x-data="{ confirmed: false }" data-loading>
                @csrf

                <fieldset class="mt-2">
                    <legend class="type-body-sm text-text-2">If you want, tell us why. It helps us improve. You can skip this.</legend>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ([['closing_shop', 'Closing the shop'], ['too_quiet', 'Too quiet'], ['not_worth_it', 'Not worth it'], ['switching', 'Switching to something else'], ['other', 'Other']] as [$reasonValue, $reasonLabel])
                            <label class="pill pill-neutral cursor-pointer">
                                <input type="radio" name="end_reason" value="{{ $reasonValue }}" class="sr-only">
                                <span>{{ $reasonLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <label class="flex items-start gap-3 mt-4">
                    <input type="checkbox" name="confirm" value="1" required class="mt-1" x-model="confirmed"
                        aria-invalid="{{ $errors->has('confirm') ? 'true' : 'false' }}"
                        @if ($errors->has('confirm')) aria-describedby="confirm-error" @endif>
                    <span>I understand what ending my program does.</span>
                </label>
                @error('confirm') <p class="form-error" id="confirm-error">{{ $message }}</p> @enderror

                <p class="mt-4 flex flex-wrap items-center gap-3">
                    <button type="submit" class="btn btn-danger" :disabled="! confirmed">End my program</button>
                    <a href="{{ route('settings.account') }}" class="btn btn-secondary">Keep my program</a>
                </p>
            </form>
        @endif
    </div>
        </div>
    </div>
@endsection
