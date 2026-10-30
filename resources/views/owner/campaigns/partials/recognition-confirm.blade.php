{{-- ID-02 (Amendment 48): the recognition confirm panel.
     Branches mirror CampaignsController::recognitionConfirm:
     stale (TR-09c) / standings moved (TR-09a regenerated,
     TR-09b customised, no send form) / leader no longer a
     sendable leader (leader-scoped F1-Z form when the
     leader's own id is recency-skipped, TR-09d otherwise)
     / flow inactive (no send form) / the scoped send form
     (TR-08 scope + TR-11 lock line). --}}
<div class="panel mt-5">
    <p class="font-medium text-text-1">Sending to: {{ $recognition['recipientName'] }} (1 customer)</p>

    <p class="type-body-sm mt-4 text-text-2">Your message as customers will receive it:</p>
    <div class="mt-3">
        @include('owner.campaigns.partials.phone-preview', ['text' => $figures['rendered_body']])
    </div>

    @include('owner.campaigns.partials.flow-state', ['closedLine' => 'Your program has ended, so this text cannot be sent.'])

    <p class="mt-3 text-text-2">Text charges join your monthly Square charge as their own line. The $99 monthly cap covers visit charges only, so a month with texts can total more than $99.</p>

    @if ($recognition['stale'])
        <p class="mt-5 font-medium text-text-1">This thank-you was for {{ $recognition['monthName'] }}, and that month has closed. It was not sent.</p>
        <div class="mt-3 flex flex-wrap items-center gap-3">
            <form method="POST" action="{{ route('campaigns.destroy', $campaign) }}" data-loading>
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-secondary">Discard</button>
            </form>
        </div>
    @elseif ($recognition['notice'] === 'changed_customized')
        <p class="mt-5 font-medium text-text-1">The standings changed: the count is now {{ $recognition['currentCount'] }}. Update your text, then send again.</p>
        <div class="mt-3 flex flex-wrap items-center gap-3">
            <a class="btn btn-secondary" href="{{ route('campaigns.recognition.edit', $campaign) }}">Back to edit</a>
        </div>
        <p class="mt-3 text-text-1">This draft stays saved.</p>
    @elseif ($recognition['notice'] === 'ineligible' && $figures['recipients'] === 0)
        @if ($recognition['leaderRecencySkipped'] && $recognition['leaderReturnDate'] !== null)
            {{-- F1-Z branch, LEADER-SCOPED (two-key Condition
                 2): it renders only when the leader's OWN id is
                 recency-skipped, its wording is scoped to this
                 customer, and the date is the leader's own
                 earliest return. The whole-audience "Every
                 customer who agreed to texts..." sentence and
                 any other customer's date never render on
                 this one-person surface. --}}
            <p class="mt-5 font-medium text-text-1">Total $0.00 for 0 customers. This customer got a campaign text too recently.</p>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <a class="btn btn-secondary" href="{{ route('campaigns.recognition.edit', $campaign) }}">Back to edit</a>
            </div>
            <p class="mt-3 text-text-1">This draft stays saved. This customer can get texts again starting {{ $recognition['leaderReturnDate'] }}.</p>
        @else
            <p class="mt-5 font-medium text-text-1">This customer cannot receive texts right now, so the thank-you was not sent.</p>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <a class="btn btn-secondary" href="{{ route('campaigns.recognition.edit', $campaign) }}">Back to edit</a>
            </div>
            <p class="mt-3 text-text-1">This draft stays saved.</p>
        @endif
    @elseif (($flowState ?? 'active') !== 'active')
        <div class="mt-3 flex flex-wrap items-center gap-3">
            <a class="btn btn-secondary" href="{{ route('campaigns.recognition.edit', $campaign) }}">Back to edit</a>
        </div>
        <p class="mt-3 text-text-1">This draft stays saved.</p>
    @elseif ($figures['recipients'] === 0)
        <p class="mt-5 font-medium text-text-1">Total $0.00 for 0 customers. There is nobody this text can be sent to right now.</p>
        <div class="mt-3 flex flex-wrap items-center gap-3">
            <a class="btn btn-secondary" href="{{ route('campaigns.recognition.edit', $campaign) }}">Back to edit</a>
        </div>
        <p class="mt-3 text-text-1">This draft stays saved.</p>
    @elseif ($figures['fits_today'] <= 0)
        <p class="mt-5 font-medium text-text-1">Total $0.00 for 0 customers today. You have used today's limit of {{ (int) config('campaigns.daily_cap') }} texts. The other {{ $figures['recipients'] }} will not be sent.</p>
        <div class="mt-3 flex flex-wrap items-center gap-3">
            <a class="btn btn-secondary" href="{{ route('campaigns.recognition.edit', $campaign) }}">Back to edit</a>
        </div>
        <p class="mt-3 text-text-1">This draft stays saved. You can send it tomorrow.</p>
    @else
        @if ($recognition['notice'] === 'changed_regenerated')
            <p class="mt-3 text-text-1">The standings changed since you last saw them. The text above uses the updated count.</p>
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
                    <dd>1</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3 border-t border-line pt-2">
                    <dt class="font-semibold">Total</dt>
                    <dd class="tile-value-sm">{{ '$'.number_format($figures['metered_cents'] / 100, 2) }}</dd>
                </div>
            </dl>
        </div>

        <p class="mt-5 font-medium text-text-1">Total {{ '$'.number_format($figures['metered_cents'] / 100, 2) }} for 1 customer. Once sent, this text cannot be unsent.</p>

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <form method="POST" action="{{ route('campaigns.send', $campaign) }}" data-loading>
                @csrf
                <button type="submit" class="btn btn-primary">Send to 1 customer</button>
            </form>
            <a class="btn btn-secondary" href="{{ route('campaigns.recognition.edit', $campaign) }}">Back to edit</a>
        </div>

        @if ($recognition['nextDraftId'] !== null)
            <p class="mt-4 text-text-2">There is another thank-you for this month waiting: <a href="{{ route('campaigns.confirm', $recognition['nextDraftId']) }}">the one for {{ $recognition['nextDraftName'] }}</a>.</p>
        @endif
    @endif
</div>
