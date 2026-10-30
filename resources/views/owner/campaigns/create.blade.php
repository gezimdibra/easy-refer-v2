@extends('layouts.app')

@section('title', 'New text campaign')

@section('content')
    {{-- Compose (spec Section 10.3; DASH-09, DASH-10): the owner
         types the body only. The prefix and the STOP suffix are
         system parts, shown locked in the preview, and every
         number on this page (segments, recipients, cost) is
         computed by the server: the client never does money
         math. Sending saves a draft and goes to the confirm
         screen; nothing sends from here. --}}
    <h1 class="type-title-2 text-text-1">New text campaign</h1>

    @if ($available && ($flowState ?? 'active') !== 'closed')
        {{-- Presets (LV-01; CAMP-16..19): each button starts a
             draft with a ready-made body and lands on the
             confirm screen. The bodies are message text only;
             the locked prefix and STOP suffix are system parts. --}}
        <div class="mt-5" data-presets-quiet>
            <h2 class="type-title-3 text-text-1">Start from a ready-made message</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($presets as $key => $preset)
                    <form method="POST" action="{{ route('campaigns.preset') }}" data-loading>
                        @csrf
                        <input type="hidden" name="preset" value="{{ $key }}">
                        <button type="submit" class="btn btn-secondary">{{ $preset['name'] }}</button>
                    </form>
                @endforeach
            </div>
            {{-- ID-03 (Amendment 46, WB-03..WB-06): the segment
                 line under the win-back preset. The count is the
                 SENDABLE count from the same read the send
                 honours — never the raw segment size beside an
                 action. --}}
            @php($segEx = $segmentAudience['exclusions'])
            {{-- TS3-10 (Sweep-03, Amendment 52): the printed
                 definition names the segment's operative legs
                 (the qualifying visit, the still-active link,
                 no visit on the way); the shipped simplification
                 admitted referrers the code excludes. The WB-05
                 zero form is scoped to who can get the text
                 (WB-06's shape): against the code's segment the
                 "quiet right now" claim was false whenever the
                 only quiet referrers were token-revoked, holding
                 a live pending referral, or held/denied-only. --}}
            <p class="type-body-sm mt-3 text-text-2">Past referrers are customers who sent a friend who visited, whose referral link is still active and who have no visit on the way, and who have not sent a new referral in {{ (int) config('campaigns.winback_lapse_days') }} days or more.</p>
            @if (($segmentAudience['memberships']->count()) > 0)
                <p class="type-body-sm mt-1 text-text-2">{{ $segmentAudience['memberships']->count() }} past {{ \Illuminate\Support\Str::plural('referrer', $segmentAudience['memberships']->count()) }} can get this text now.</p>
            @elseif (($segmentAudience['segment_size'] ?? 0) === 0)
                <p class="type-body-sm mt-1 text-text-2">No past referrers can get a win-back text right now.</p>
            @else
                <p class="type-body-sm mt-1 text-text-2">No past referrers can get this text now.</p>
                {{-- WB-06 (Amendment 46), Condition 1 (two-key):
                     the headline never renders bare — every
                     member of a non-empty filtered segment is
                     counted under exactly one cause, so at
                     least one clause below always renders,
                     including when the zero is caused solely
                     by consent/provenance exclusions. --}}
                @if (($segEx['opted_out'] ?? 0) > 0)
                    <p class="type-body-sm mt-1 text-text-2">{{ $segEx['opted_out'] }} opted out.</p>
                @endif
                @if (($segEx['no_consent'] ?? 0) > 0)
                    <p class="type-body-sm mt-1 text-text-2">{{ $segEx['no_consent'] }} with no consent on file.</p>
                @endif
                @if (($segEx['owner_asserted_unconfirmed'] ?? 0) > 0)
                    <p class="type-body-sm mt-1 text-text-2">{{ $segEx['owner_asserted_unconfirmed'] }} added by you and not yet confirmed.</p>
                @endif
                @if ((($segEx['recently_texted_spacing'] ?? 0) + ($segEx['recently_texted_cap'] ?? 0)) > 0)
                    <p class="type-body-sm mt-1 text-text-2">{{ ($segEx['recently_texted_spacing'] ?? 0) + ($segEx['recently_texted_cap'] ?? 0) }} got a campaign text too recently.</p>
                @endif
                @if (($segEx['winback_cooldown'] ?? 0) > 0)
                    <p class="type-body-sm mt-1 text-text-2">{{ $segEx['winback_cooldown'] }} {{ $segEx['winback_cooldown'] === 1 ? 'was' : 'were' }} sent a win-back text in the last {{ (int) config('campaigns.winback_cooldown_days') }} days.</p>
                @endif
            @endif
        </div>

        {{-- ID-01 Phase 1 (Amendment 46, SD-06/SD-07): the
             quiet-day card. The pick is the OWNER'S declaration
             — the owner is the sensor — and the Action Center
             prompt on that morning is only a reminder of their
             own pick. The owner tap above (the "Fill a slow
             day" preset) works on any day, pick or no pick. --}}
        <div class="panel mt-5">
            <h2 class="type-title-3 text-text-1">Your quiet day</h2>
            <p class="type-body-sm mt-2 text-text-2">Pick the day your shop is usually quietest. On that day, when your program is active and enough customers can get texts, we will set up a ready-made text for your customers in your Action Center. You always review it before anything sends.</p>
            @if ($business->slow_day_reminders_off)
                <p class="mt-3 text-text-1">Slow-day reminders are off.</p>
                <form method="POST" action="{{ route('campaigns.quiet-day') }}" class="mt-3" data-loading>
                    @csrf
                    <input type="hidden" name="slow_day_weekday" value="{{ $business->slow_day_weekday }}">
                    <input type="hidden" name="reminders_on" value="1">
                    <button type="submit" class="btn btn-secondary">Turn reminders back on</button>
                </form>
            @else
                <form method="POST" action="{{ route('campaigns.quiet-day') }}" class="mt-3 flex flex-wrap items-center gap-2" data-loading>
                    @csrf
                    <label class="form-label" for="slow_day_weekday">Quiet day</label>
                    <select id="slow_day_weekday" name="slow_day_weekday" class="form-input form-input-app">
                        <option value="" @selected($business->slow_day_weekday === null)>No quiet day</option>
                        @foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $weekday => $weekdayName)
                            <option value="{{ $weekday }}" @selected($business->slow_day_weekday === $weekday)>{{ $weekdayName }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-secondary">Save</button>
                </form>
            @endif
        </div>
    @endif

    @if (! $available)
        @include('partials.campaigns-unavailable')
    @elseif (($flowState ?? 'active') === 'closed')
        {{-- F2 (Amendment 45): a closed business's program has
             ended; there is no compose path to a send. --}}
        <div class="panel mt-5">
            @include('owner.campaigns.partials.flow-state', ['closedLine' => 'Your program has ended, so text campaigns cannot be sent.'])
        </div>
    @else
        <form method="POST" action="{{ route('campaigns.store') }}" class="panel mt-5" data-loading
            x-data="{
                body: @js(old('body', $draft?->body ?? '')),
                rendered: @js($figures['rendered_body']),
                segments: {{ $figures['segments'] }},
                encoding: @js($figures['encoding']),
                totalCents: {{ $figures['total_cents'] }},
                recipients: {{ $figures['recipients'] }},
                timer: null,
                refresh() {
                    clearTimeout(this.timer);
                    this.timer = setTimeout(() => {
                        fetch(@js($estimateUrl), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            },
                            body: JSON.stringify({ body: this.body, draft_id: @js($draft?->id) }),
                        }).then(r => r.json()).then(d => {
                            this.rendered = d.rendered_body;
                            this.segments = d.segments;
                            this.encoding = d.encoding;
                            this.totalCents = d.total_cents;
                            this.recipients = d.recipients;
                        }).catch(() => {});
                    }, 350);
                }
            }">
            @csrf
            @if ($draft)
                <input type="hidden" name="draft_id" value="{{ $draft->id }}">
            @endif

            <label class="form-label" for="body">Message</label>
            <textarea id="body" name="body" rows="4" class="form-input form-input-app"
                x-model="body" @input="refresh()">{{ old('body', $draft?->body ?? '') }}</textarea>
            @error('body')
                <p class="mt-2 text-danger">{{ $message }}</p>
            @enderror

            <p class="type-body-sm mt-4 text-text-2">Your message as customers will receive it:</p>
            <div class="mt-3">
                @include('owner.campaigns.partials.phone-preview', ['alpine' => true])
            </div>

            @if ($draft?->audience_segment === 'lapsed_referrers')
                {{-- ID-03 (design S8): a segment draft's audience
                     is a locked fact in the composer — the body
                     is editable, the audience is not. There is
                     no widen control; discard is the only exit. --}}
                <p class="mt-4 font-medium text-text-1">Audience: past referrers ({{ $figures['recipients'] }})</p>
            @endif
            <p class="mt-4 font-medium text-text-1" x-text="'Sending to ' + recipients + ' ' + (recipients === 1 ? 'customer' : 'customers') + ' who agreed to receive messages.'">Sending to {{ $figures['recipients'] }} {{ \Illuminate\Support\Str::plural('customer', $figures['recipients']) }} who agreed to receive messages.</p>
            <p class="type-body-sm mt-1 text-text-2">Only customers who agreed to receive text messages, and who have not opted out, will receive this.</p>
            @include('owner.campaigns.partials.flow-state', ['closedLine' => 'Your program has ended, so text campaigns cannot be sent.'])
            {{-- FQ-01 (Amendment 45): the cap stated as a rule,
                 true in every render state. --}}
            <p class="type-body-sm mt-1 text-text-2">We skip anyone who got a campaign text in the last {{ (int) config('campaigns.recency_spacing_days') }} days, and anyone who has had {{ (int) config('campaigns.recency_max_touches') }} campaign texts in the last {{ (int) config('campaigns.recency_window_days') }} days.</p>
            @php($exclusions = $audience['exclusions'])
            @if (array_sum($exclusions) > 0)
                {{-- FQ-07 (Amendment 45): the two recency clauses
                     append inside this sentence, each only when its
                     count > 0. They are conditional ECHOES, never
                     inline @if: an @if glued to the preceding word
                     is left literal by the Blade compiler (D-1,
                     deploy49 re-check — the directives printed as
                     visible text and both clauses rendered in every
                     state), and block @if on its own lines leaves
                     whitespace that renders as "on file , 1 texted".
                     Pinned by ComposerNotCountedLeakTest. --}}
                <p class="type-body-sm mt-1 text-text-2">Not counted: {{ $exclusions['owner_asserted_unconfirmed'] }} added by you and not yet confirmed, {{ $exclusions['opted_out'] }} opted out, {{ $exclusions['no_consent'] }} with no consent on file{{ ($exclusions['recently_texted_spacing'] ?? 0) > 0 ? ', '.$exclusions['recently_texted_spacing'].' texted in the last '.(int) config('campaigns.recency_spacing_days').' days' : '' }}{{ ($exclusions['recently_texted_cap'] ?? 0) > 0 ? ', '.$exclusions['recently_texted_cap'].' at the most texts for now' : '' }}.</p>
            @endif

            {{-- R2 CAMP-COMPOSE: the cost facts as a tile strip,
                 not a prose sentence. Values stay server-computed
                 and live-refreshed by the same estimate call. --}}
            <div class="mt-4 grid grid-cols-3 gap-2">
                <div class="rounded-md bg-surface-1 px-2 py-2">
                    <p class="tile-label tracking-[0.04em]">Customers</p>
                    <p class="tile-value-sm mt-1" x-text="recipients">{{ $figures['recipients'] }}</p>
                </div>
                <div class="rounded-md bg-surface-1 px-2 py-2">
                    <p class="tile-label tracking-[0.04em]">Segments each</p>
                    <p class="tile-value-sm mt-1" x-text="segments">{{ $figures['segments'] }}</p>
                </div>
                <div class="rounded-md bg-surface-1 px-2 py-2">
                    <p class="tile-label tracking-[0.04em]">Total</p>
                    <p class="tile-value-sm mt-1" x-text="'$' + (totalCents / 100).toFixed(2)">{{ '$'.number_format($figures['total_cents'] / 100, 2) }}</p>
                </div>
            </div>
            <p class="type-body-sm mt-2 text-text-2" x-show="encoding === 'Unicode'" x-cloak>Unicode message (emoji and some symbols count differently)</p>

            @if ($figures['recipients'] === 0 && (($exclusions['recently_texted_spacing'] ?? 0) + ($exclusions['recently_texted_cap'] ?? 0)) > 0)
                {{-- FQ-05 (Amendment 45): the recency-zero
                     composer form. The draft path stays open:
                     the owner may compose now and send later. --}}
                <p class="mt-4 text-text-1">All of your customers who agreed to texts got a campaign text too recently. Some can get texts again starting {{ $business->localTime($audience['recency_earliest_return'])->format('M j, Y') }}.</p>
                <p class="mt-5">
                    <button type="submit" class="btn btn-primary">Review before sending</button>
                </p>
            @elseif ($figures['recipients'] === 0)
                <p class="mt-4 text-text-1">No customers have confirmed yet, so there is nobody to send to. Customers confirm by replying YES to the confirmation text, or by agreeing when they join.</p>
            @else
                <p class="mt-5">
                    <button type="submit" class="btn btn-primary">Review before sending</button>
                </p>
            @endif
        </form>
    @endif
@endsection
