@extends('layouts.app')

@section('title', 'Campaign')

@section('content')
    {{-- Campaign detail (spec Section 10.3; DASH-09 progress
         shape): the counts as sent, the money as confirmed, and
         the per-recipient truth with masked numbers (Section
         5.1: full numbers live on the customer page). While the
         campaign is sending, the page polls the status endpoint
         on the app's Alpine pattern. --}}
    <h1 class="type-title-2 text-text-1">Campaign</h1>

    <div class="panel mt-5"
        @if ($campaign->status === 'sending')
            x-data="{ poll() { fetch(@js(route('campaigns.status', $campaign)), { headers: { 'Accept': 'application/json' } }).then(r => r.json()).then(d => { if (d.status !== 'sending') { window.location.reload(); } }).catch(() => {}) } }"
            x-init="setInterval(() => poll(), 5000)"
        @endif
    >
        @if ($campaign->isRecognition())
            {{-- TR-10 (ID-02): the provenance label. --}}
            <p class="type-body-sm text-text-2">Top referrer thank-you</p>
        @endif

        @if ($campaign->status === 'sending')
            <p class="font-medium text-text-1">Sending {{ $processed }} of {{ $total }}</p>
        @elseif ($campaign->status === 'failed')
            <p class="font-medium text-text-1">This campaign could not be sent.</p>
        @else
            <p class="font-medium text-text-1">Sent {{ $business->localTime($campaign->sent_at ?? $campaign->created_at)->format('M j, Y') }}</p>
        @endif

        {{-- RK-02: for a completed campaign the Results panel
             below is the summary (CAMP-22), so the counts and
             money blocks render only for the states that have
             no results yet (draft, sending, could-not-send);
             no figure ever renders twice on this page. --}}
        @if ($results === null)
            <ul class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-text-1">
                <li>Queued: {{ $counts['queued'] }}</li>
                <li>Sent: {{ $counts['sent'] }}</li>
                <li>Delivered: {{ $counts['delivered'] }}</li>
                <li>Failed: {{ $counts['failed'] + $counts['undelivered'] }}</li>
                <li>Skipped: {{ $counts['skipped'] }}</li>
                <li>Not sent: {{ $counts['not_sent'] }}</li>
            </ul>
        @endif

        <p class="type-body-sm mt-4 text-text-2">The message as sent:</p>
        <div class="mt-3">
            @include('owner.campaigns.partials.phone-preview', ['text' => $renderedBody])
        </div>

        {{-- R26 Gate 3 follow-up (wording DRAFT for addendum
             29.1): the stamped not_sent_count summary stays
             neutral ("Not sent") rather than asserting the
             daily limit, because the page does not prove one
             reason for that stamped figure; the per-recipient
             rows below carry the specific reasons. --}}
        @if ($results === null)
            <ul class="mt-4 space-y-1 text-text-1">
                <li>{{ $campaign->segment_count }} segments per customer</li>
                <li>Total cost: {{ '$'.number_format($campaign->metered_cents / 100, 2) }}</li>
                @if ($campaign->not_sent_count > 0)
                    <li>Not sent: {{ $campaign->not_sent_count }}</li>
                @endif
                {{-- FQ-06 (Amendment 45): the recency skips,
                     stamped at send; frozen, never recomputed. --}}
                @if (($campaign->recent_spacing_skipped_count + $campaign->recent_cap_skipped_count) > 0)
                    <li>Skipped (texted too recently): {{ $campaign->recent_spacing_skipped_count + $campaign->recent_cap_skipped_count }}</li>
                @endif
                @if (($campaign->winback_cooldown_skipped_count ?? 0) > 0)
                    <li>Skipped (sent a win-back text in the last {{ (int) config('campaigns.winback_cooldown_days') }} days): {{ $campaign->winback_cooldown_skipped_count }}</li>
                @endif
            </ul>
        @else
            @if ($campaign->not_sent_count > 0)
                <p class="mt-4 text-text-1">Not sent: {{ $campaign->not_sent_count }}</p>
            @endif
            @if (($campaign->recent_spacing_skipped_count + $campaign->recent_cap_skipped_count) > 0)
                <p class="mt-4 text-text-1">Skipped (texted too recently): {{ $campaign->recent_spacing_skipped_count + $campaign->recent_cap_skipped_count }}</p>
            @endif
            @if (($campaign->winback_cooldown_skipped_count ?? 0) > 0)
                <p class="mt-4 text-text-1">Skipped (sent a win-back text in the last {{ (int) config('campaigns.winback_cooldown_days') }} days): {{ $campaign->winback_cooldown_skipped_count }}</p>
            @endif
        @endif
    </div>

    @if ($results !== null)
        {{-- RK-02 closed-loop results (CAMP-22/23): counts
             before any percentage (no percentage renders), the
             visit windows labelled as visits AFTER the text
             with the honesty line under them, and the one
             action: resend to the failed recipients who are
             still eligible. --}}
        <div class="panel mt-4">
            <h2 class="type-title-3 text-text-1">Results</h2>
            {{-- R2 CAMP-DETAIL (design section 4): results as
                 funnel tiles, total cost as a labeled line, the
                 honesty line promoted to an info banner. --}}
            <div class="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-md bg-surface-1 px-3 py-2">
                    <p class="tile-label">Delivered</p>
                    <p class="tile-value-sm mt-1">{{ $results['delivered'] }}</p>
                </div>
                <div class="rounded-md bg-surface-1 px-3 py-2">
                    <p class="tile-label">Failed</p>
                    <p class="tile-value-sm mt-1 {{ $results['failed'] > 0 ? 'text-error-text' : '' }}">{{ $results['failed'] }}</p>
                </div>
                <div class="rounded-md bg-surface-1 px-3 py-2">
                    <p class="tile-label">Visited within 7 days</p>
                    <p class="tile-value-sm mt-1">{{ $results['visited_7'] }}</p>
                </div>
                <div class="rounded-md bg-surface-1 px-3 py-2">
                    <p class="tile-label">Visited within 14 days</p>
                    <p class="tile-value-sm mt-1">{{ $results['visited_14'] }}</p>
                </div>
            </div>
            <ul class="mt-3 space-y-1 text-text-1">
                <li>Opted out: {{ $results['opted_out'] }}</li>
                <li>Total cost: {{ '$'.number_format($results['cost_cents'] / 100, 2) }}</li>
            </ul>
            <p class="banner banner-info mt-3">This counts visits that happened after the text. It does not prove the text caused them.</p>

            @if ($results['failed'] > 0)
                <div class="mt-4 border-t border-line pt-4">
                    <p class="type-body text-text-2">The customers whose texts failed can be tried again. Only those who still agree to texts are included.</p>
                    @if (count($results['resend_ids']) > 0)
                        <form method="POST" action="{{ route('campaigns.resend', $campaign) }}" class="mt-3" data-loading>
                            @csrf
                            <button type="submit" class="btn btn-primary">Resend to {{ count($results['resend_ids']) }} {{ \Illuminate\Support\Str::plural('customer', count($results['resend_ids'])) }}</button>
                        </form>
                    @else
                        <p class="type-body mt-3 text-text-2">None of the failed numbers can receive texts anymore.</p>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <div class="mt-4 overflow-hidden rounded-lg border border-line bg-surface-0 shadow-e1">
        <div class="overflow-x-auto"><table class="tbl tbl-sticky tbl-stack">
            <thead>
                <tr>
                    <th scope="col">Customer</th>
                    <th scope="col">Phone</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recipients as $recipient)
                    <tr>
                        <td>{{ $recipient->customer->fullName() }}</td>
                        <td>{{ $mask($recipient->phone_e164) }}</td>
                        <td>
                            @php($statePill = match ($recipient->delivery_state) {
                                'delivered' => 'pill-success',
                                'sent', 'queued' => 'pill-info',
                                'failed', 'undelivered' => 'pill-error',
                                default => 'pill-neutral',
                            })
                            <span class="pill {{ $statePill }}">{{ ucfirst(str_replace('_', ' ', $recipient->delivery_state)) }}</span>
                            @if (in_array($recipient->delivery_state, ['failed', 'undelivered'], true))
                                <span class="type-body-sm mt-1 block text-text-2">{{ $failureReason($recipient->provider_error_code) }}</span>
                            @elseif ($recipient->delivery_state === 'skipped')
                                <span class="type-body-sm mt-1 block text-text-2">{{ match ($recipient->skip_reason) {
                                    'opted_out' => 'This number opted out before the send.',
                                    'consent_revoked' => 'This customer no longer agrees to messages.',
                                    'membership_removed' => 'This customer was removed before the send.',
                                    'recently_texted' => 'This customer got a campaign text too recently.',
                                    'winback_cooldown' => 'This customer was sent a win-back text in the last '.(int) config('campaigns.winback_cooldown_days').' days.',
                                    'no_longer_lapsed' => 'This customer sent a new referral before this text went out.',
                                    default => 'Not sent.',
                                } }}</span>
                            @elseif ($recipient->delivery_state === 'not_sent')
                                <span class="type-body-sm mt-1 block text-text-2">Over the daily limit.</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
        <div class="px-4 py-3">{{ $recipients->links() }}</div>
    </div>
@endsection
