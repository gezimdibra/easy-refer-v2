@extends('layouts.app')

@section('title', 'Review and send')

@section('content')
    {{-- Confirm (spec Section 10.3; DASH-09): the only send
         path. The figures on this page were recomputed from
         ground truth at render and stamped on the draft; the
         send compares against them and bounces back here if
         they moved. The cap sentence is required honesty: the
         $99 cap covers visit charges only. --}}
    <h1 class="type-title-2 text-text-1">Review and send</h1>

    @if (isset($recognition))
        {{-- ID-02 (Amendment 48): a recognition draft's confirm
             is a scoped surface of its own (TR-08/TR-11): one
             person, the fully rendered text, and the standings
             branches — the whole-audience machinery below never
             renders for it. --}}
        @include('owner.campaigns.partials.recognition-confirm')
    @else
    <div class="panel mt-5">
        <p class="type-body-sm text-text-2">Your message as customers will receive it:</p>
        <div class="mt-3">
            @include('owner.campaigns.partials.phone-preview', ['text' => $figures['rendered_body']])
        </div>

        @if ($campaign->audience_segment === 'lapsed_referrers' && $figures['recipients'] > 0)
            {{-- WB-09 (Amendment 46): the segment scope line
                 REPLACES the whole-list scope sentence on a
                 segment draft — both rendering would
                 double-scope the send. Grammar micro-fix:
                 count 1 renders the singular via Str::plural;
                 pinned by CampaignConfirmGrammarPluralTest. --}}
            <p class="mt-4 font-medium text-text-1">This text goes only to past referrers: {{ $figures['recipients'] }} {{ \Illuminate\Support\Str::plural('customer', $figures['recipients']) }}.</p>
        @else
            <p class="mt-4 font-medium text-text-1">Sending to {{ $figures['recipients'] }} {{ \Illuminate\Support\Str::plural('customer', $figures['recipients']) }} who agreed to receive messages.</p>
        @endif
        <p class="type-body-sm mt-1 text-text-2">Only customers who agreed to receive text messages, and who have not opted out, will receive this.</p>

        @include('owner.campaigns.partials.flow-state', ['closedLine' => 'Your program has ended, so this text cannot be sent.'])

        {{-- FQ-01 (Amendment 45): the frequency rule, mirrored
             in the confirm's decision area. --}}
        <p class="type-body-sm mt-1 text-text-2">We skip anyone who got a campaign text in the last {{ (int) config('campaigns.recency_spacing_days') }} days, and anyone who has had {{ (int) config('campaigns.recency_max_touches') }} campaign texts in the last {{ (int) config('campaigns.recency_window_days') }} days.</p>

        {{-- Preflight (LV-01; CAMP-21): who will NOT get this
             text, stated here where the send decision happens
             (the breakdown the composer has always shown,
             CAMP-09, in its decision-moment home). --}}
        {{-- R2 CAMP-CONFIRM (design section 3): exclusions keep
             their exact words, in banner chrome; the breakdown
             becomes a receipt whose Total is the big number. --}}
        @php($exclusions = $audience['exclusions'])
        @if (array_sum($exclusions) > 0)
            <div class="banner banner-warning mt-4">
                <div>
                    <p class="font-semibold">Before you send</p>
                    <p class="type-body-sm mt-1">Not counted: {{ $exclusions['owner_asserted_unconfirmed'] }} added by you and not yet confirmed, {{ $exclusions['opted_out'] }} opted out, {{ $exclusions['no_consent'] }} with no consent on file.</p>
                    {{-- FQ-02 / FQ-03 (Amendment 45): each
                         recency limb named with its own words;
                         neither borrows the other's window.
                         Grammar micro-fix: at count 1 the
                         spacing clause here and the win-back
                         clause below render singular nouns AND
                         verbs (Str::plural + ternary verb echoes
                         inside {{ }} — never a glued @if, per
                         D-1); every other count renders exactly
                         as before. Pinned by
                         CampaignConfirmGrammarPluralTest. --}}
                    @if (($exclusions['recently_texted_spacing'] ?? 0) > 0)
                        <p class="type-body-sm mt-1">{{ $exclusions['recently_texted_spacing'] }} {{ \Illuminate\Support\Str::plural('customer', $exclusions['recently_texted_spacing']) }} got a campaign text in the last {{ (int) config('campaigns.recency_spacing_days') }} days and {{ $exclusions['recently_texted_spacing'] === 1 ? 'is' : 'are' }} skipped.</p>
                    @endif
                    @if (($exclusions['recently_texted_cap'] ?? 0) > 0)
                        <p class="type-body-sm mt-1">{{ $exclusions['recently_texted_cap'] }} {{ \Illuminate\Support\Str::plural('customer', $exclusions['recently_texted_cap']) }} {{ $exclusions['recently_texted_cap'] === 1 ? 'has' : 'have' }} had {{ (int) config('campaigns.recency_max_touches') }} campaign texts in the last {{ (int) config('campaigns.recency_window_days') }} days, the most we send, and are skipped.</p>
                    @endif
                    {{-- ID-03 (Amendment 46): the win-back
                         cooldown clause, after F1's, each
                         counted once under the precedence. --}}
                    @if (($exclusions['winback_cooldown'] ?? 0) > 0)
                        <p class="type-body-sm mt-1">{{ $exclusions['winback_cooldown'] }} {{ \Illuminate\Support\Str::plural('customer', $exclusions['winback_cooldown']) }} {{ $exclusions['winback_cooldown'] === 1 ? 'was' : 'were' }} sent a win-back text in the last {{ (int) config('campaigns.winback_cooldown_days') }} days and {{ $exclusions['winback_cooldown'] === 1 ? 'is' : 'are' }} skipped.</p>
                    @endif
                </div>
            </div>
        @endif

        <div class="mt-4 rounded-md bg-surface-1 px-4 py-3">
            <dl class="space-y-1 text-text-1">
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-text-2">Segments per customer</dt>
                    <dd>{{ $figures['segments'] }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-text-2">Price per segment</dt>
                    <dd>{{ $segmentPriceCents }} cents</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-text-2">Customers</dt>
                    {{-- Amendment 38 (Train A3-5, F-18): in the
                         capped states the receipt row names the
                         fits count beside the full audience, so
                         the receipt cannot be quoted against the
                         scope line below. --}}
                    <dd>{{ $figures['fits_today'] < $figures['recipients'] ? $figures['fits_today'].' of '.$figures['recipients'].' today' : $figures['recipients'] }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3 border-t border-line pt-2">
                    <dt class="font-semibold">Total</dt>
                    <dd class="tile-value-sm">{{ '$'.number_format($figures['metered_cents'] / 100, 2) }}</dd>
                </div>
            </dl>
        </div>

        @if ($figures['segments'] > 3)
            <p class="mt-3 text-text-1">This is a long message: {{ $figures['segments'] }} segments per customer. Longer messages cost more per customer.</p>
        @endif

        {{-- TS3-13 (Sweep-03, Amendment 52): the cap line is
             suppressed in the one conjunction where it lies:
             a segment draft whose flow is inactive, where the
             branch chain below suppresses the send form itself
             (the sender refuses with blocked_flow). Whole-list
             drafts in the same cap state keep the line: their
             warn-and-allow send really can go out. --}}
        @if (($flowState ?? 'active') !== 'closed' && $figures['recipients'] > 0 && $figures['fits_today'] < $figures['recipients'] && ! ($campaign->audience_segment !== null && ($flowState ?? 'active') !== 'active'))
            <p class="mt-3 text-text-1">You can send {{ $figures['fits_today'] }} of {{ $figures['recipients'] }} today. The rest will not be sent.</p>
        @endif

        <p class="mt-3 text-text-2">Text charges join your monthly Square charge as their own line. The $99 monthly cap covers visit charges only, so a month with texts can total more than $99.</p>

        {{-- Scoped confirmation (RS-14; GEN-05 supersedes the
             CAMP-01 "Send now" on this screen): the button is
             labelled by the verb and restates the scope with
             the cost and the irreversibility, directly above
             the button it scopes. Amendment 38 (Train A3-5,
             F-18): the scope line and the button name the count
             the total actually prices (fits_today) in the
             capped states; at zero fit the send form is
             suppressed entirely (coordinator ruling, Addendum
             8): a button that sends nothing must not be labeled
             as a send, and the draft stays saved for tomorrow. --}}
        @if (($flowState ?? 'active') === 'closed')
            {{-- F2 (Amendment 45): the closed block. No send
                 form renders; the draft stays as the record of
                 what was composed, and the send POST refuses
                 as well. --}}
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <a class="btn btn-secondary" href="{{ route('campaigns.create', ['draft' => $campaign->id]) }}">Back to edit</a>
            </div>
        @elseif ($campaign->audience_segment !== null && ($flowState ?? 'active') !== 'active')
            {{-- ID-03 (Amendment 46, design 4.3 branch 1): a
                 segment draft's confirm re-checks flow state.
                 No send form while the flow is inactive; the
                 draft stays saved and the flow state's own
                 surfaces (the partial above, the past-due and
                 setup rows) own the explanation — this block
                 adds no competing words. --}}
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <a class="btn btn-secondary" href="{{ route('campaigns.create', ['draft' => $campaign->id]) }}">Back to edit</a>
            </div>
            <p class="mt-3 text-text-1">This draft stays saved.</p>
        @elseif ($campaign->audience_segment !== null && $figures['recipients'] === 0 && ($audience['segment_size'] ?? 0) === 0)
            {{-- WB-Z (Amendment 46, WB-10): the segment itself
                 is empty. Never the daily-limit words, never
                 the no-customers words, never the recency
                 words — none of those causes is this cause. --}}
            <p class="mt-5 font-medium text-text-1">Total $0.00 for 0 customers. No past referrers are quiet right now.</p>

            <div class="mt-3 flex flex-wrap items-center gap-3">
                <a class="btn btn-secondary" href="{{ route('campaigns.create', ['draft' => $campaign->id]) }}">Back to edit</a>
            </div>
            <p class="mt-3 text-text-1">This draft stays saved. Past referrers go quiet {{ (int) config('campaigns.winback_lapse_days') }} days after their last referral.</p>
        @elseif ($campaign->audience_segment !== null && $figures['recipients'] === 0 && ($exclusions['winback_cooldown'] ?? 0) > 0 && (($exclusions['recently_texted_spacing'] ?? 0) + ($exclusions['recently_texted_cap'] ?? 0)) === 0)
            {{-- ID-03 cooldown-zero (design 4.3 branch 3):
                 K3's shape, the cooldown's own words and its
                 own earliest date. Condition 1 (two-key): the
                 "Every past referrer" sentence is scoped to
                 the state where cooldown is the WHOLE story —
                 when consent/provenance exclusions coexist in
                 the same audience, those members are quiet for
                 a different cause, so the line names only the
                 cooldown count it can prove (the banner above
                 names the other causes by count). Grammar
                 micro-fix: at cooldown count 1 the scoped line
                 renders "1 past referrer was sent..."; pinned by
                 CampaignConfirmGrammarPluralTest. --}}
            @if (($exclusions['opted_out'] ?? 0) + ($exclusions['no_consent'] ?? 0) + ($exclusions['owner_asserted_unconfirmed'] ?? 0) === 0)
                <p class="mt-5 font-medium text-text-1">Total $0.00 for 0 customers. Every past referrer who is quiet was sent a win-back text in the last {{ (int) config('campaigns.winback_cooldown_days') }} days.</p>
            @else
                <p class="mt-5 font-medium text-text-1">Total $0.00 for 0 customers. {{ $exclusions['winback_cooldown'] }} past {{ \Illuminate\Support\Str::plural('referrer', $exclusions['winback_cooldown']) }} {{ $exclusions['winback_cooldown'] === 1 ? 'was' : 'were' }} sent a win-back text in the last {{ (int) config('campaigns.winback_cooldown_days') }} days.</p>
            @endif

            <div class="mt-3 flex flex-wrap items-center gap-3">
                <a class="btn btn-secondary" href="{{ route('campaigns.create', ['draft' => $campaign->id]) }}">Back to edit</a>
            </div>
            <p class="mt-3 text-text-1">This draft stays saved. The first of them can get a win-back text again starting {{ $business->localTime($audience['winback_earliest_return'])->format('M j, Y') }}.</p>
        @elseif ($figures['recipients'] === 0 && (($exclusions['recently_texted_spacing'] ?? 0) + ($exclusions['recently_texted_cap'] ?? 0)) > 0)
            {{-- F1-Z (Amendment 45): K3's shape, recency's own
                 words. Never the daily-limit sentence, never
                 the no-customers sentence, never a bare
                 "tomorrow": the date is computed from the
                 skipped members' own windows. --}}
            @if ($campaign->audience_segment !== null && ($exclusions['winback_cooldown'] ?? 0) > 0)
                {{-- ID-03 (design 4.3 branch 4), Condition 1
                     (two-key): under precedence counting the
                     F1 and cooldown sets are DISJOINT — a member
                     counted under F1 is never also counted under
                     the cooldown, and a cooldown member passed
                     F1. The lines therefore name two separate
                     groups by their own counts and causes, never
                     one group with an "also": the F1 group got a
                     campaign text too recently, the OTHER group
                     was sent a win-back text. --}}
                <p class="mt-5 font-medium text-text-1">Total $0.00 for 0 customers. {{ ($exclusions['recently_texted_spacing'] ?? 0) + ($exclusions['recently_texted_cap'] ?? 0) }} past {{ \Illuminate\Support\Str::plural('referrer', ($exclusions['recently_texted_spacing'] ?? 0) + ($exclusions['recently_texted_cap'] ?? 0)) }} got a campaign text too recently.</p>
                <p class="mt-3 text-text-1">{{ $exclusions['winback_cooldown'] }} other past {{ \Illuminate\Support\Str::plural('referrer', $exclusions['winback_cooldown']) }} {{ $exclusions['winback_cooldown'] === 1 ? 'was' : 'were' }} sent a win-back text in the last {{ (int) config('campaigns.winback_cooldown_days') }} days.</p>
            @elseif ($campaign->audience_segment !== null)
                {{-- TS3-02 (Sweep-03, Amendment 52): the pure-F1
                     segment zero. The audience behind this page
                     is segment-scoped, so the sentence names only
                     the segment's own F1 group, the branch-4
                     line's first half standing alone. The
                     whole-list "Every customer who agreed to
                     texts" form below is valid only on whole-list
                     drafts, whose counts really are whole-list. --}}
                <p class="mt-5 font-medium text-text-1">Total $0.00 for 0 customers. {{ ($exclusions['recently_texted_spacing'] ?? 0) + ($exclusions['recently_texted_cap'] ?? 0) }} past {{ \Illuminate\Support\Str::plural('referrer', ($exclusions['recently_texted_spacing'] ?? 0) + ($exclusions['recently_texted_cap'] ?? 0)) }} got a campaign text too recently.</p>
            @else
                <p class="mt-5 font-medium text-text-1">Total $0.00 for 0 customers. Every customer who agreed to texts got a campaign text too recently.</p>
            @endif

            <div class="mt-3 flex flex-wrap items-center gap-3">
                <a class="btn btn-secondary" href="{{ route('campaigns.create', ['draft' => $campaign->id]) }}">Back to edit</a>
            </div>
            @php($f1zReturn = $audience['recency_earliest_return'])
            @if ($campaign->audience_segment !== null && ($audience['winback_earliest_return'] ?? null) !== null && $audience['winback_earliest_return']->lessThan($f1zReturn))
                {{-- Condition 1: the two groups are disjoint,
                     each bound only by the reason it was counted
                     under, so the first date ANY of them can get
                     a text again is the EARLIER of the two
                     groups' earliest returns. --}}
                @php($f1zReturn = $audience['winback_earliest_return'])
            @endif
            <p class="mt-3 text-text-1">This draft stays saved. Some customers can get texts again starting {{ $business->localTime($f1zReturn)->format('M j, Y') }}.</p>
        @elseif ($figures['recipients'] === 0)
            {{-- Cause-routed zero (Amendment 45 build note):
                 nobody is sendable and recency is not the
                 cause, so no send form renders either; the
                 words name no cause this page cannot prove. --}}
            <p class="mt-5 font-medium text-text-1">Total $0.00 for 0 customers. There is nobody this text can be sent to right now.</p>

            <div class="mt-3 flex flex-wrap items-center gap-3">
                <a class="btn btn-secondary" href="{{ route('campaigns.create', ['draft' => $campaign->id]) }}">Back to edit</a>
            </div>
            <p class="mt-3 text-text-1">This draft stays saved.</p>
        @elseif ($figures['fits_today'] <= 0 && $figures['recipients'] > 0)
            <p class="mt-5 font-medium text-text-1">Total $0.00 for 0 customers today. You have used today's limit of {{ (int) config('campaigns.daily_cap') }} texts. The other {{ $figures['recipients'] }} will not be sent.</p>

            <div class="mt-3 flex flex-wrap items-center gap-3">
                <a class="btn btn-secondary" href="{{ route('campaigns.create', ['draft' => $campaign->id]) }}">Back to edit</a>
            </div>
            <p class="mt-3 text-text-1">This draft stays saved. You can send it tomorrow.</p>
        @else
            @if ($figures['fits_today'] < $figures['recipients'])
                <p class="mt-5 font-medium text-text-1">Total {{ '$'.number_format($figures['metered_cents'] / 100, 2) }} for {{ $figures['fits_today'] }} {{ \Illuminate\Support\Str::plural('customer', $figures['fits_today']) }} today. The other {{ $figures['recipients'] - $figures['fits_today'] }} will not be sent. Once sent, this text cannot be unsent.</p>
            @else
                <p class="mt-5 font-medium text-text-1">Total {{ '$'.number_format($figures['metered_cents'] / 100, 2) }} for {{ $figures['recipients'] }} {{ \Illuminate\Support\Str::plural('customer', $figures['recipients']) }}. Once sent, this text cannot be unsent.</p>
            @endif

            <div class="mt-3 flex flex-wrap items-center gap-3">
                <form method="POST" action="{{ route('campaigns.send', $campaign) }}" data-loading>
                    @csrf
                    <button type="submit" class="btn btn-primary">Send to {{ $figures['fits_today'] < $figures['recipients'] ? $figures['fits_today'] : $figures['recipients'] }} {{ \Illuminate\Support\Str::plural('customer', $figures['fits_today'] < $figures['recipients'] ? $figures['fits_today'] : $figures['recipients']) }}</button>
                </form>
                <a class="btn btn-secondary" href="{{ route('campaigns.create', ['draft' => $campaign->id]) }}">Back to edit</a>
            </div>
        @endif
    </div>
    @endif
@endsection
