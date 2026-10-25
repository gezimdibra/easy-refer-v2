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
        @if ($campaign->status === 'sending')
            <p class="font-medium text-text-1">Sending {{ $processed }} of {{ $total }}</p>
        @elseif ($campaign->status === 'failed')
            <p class="font-medium text-text-1">This campaign could not be sent.</p>
        @else
            <p class="font-medium text-text-1">Sent {{ ($campaign->sent_at ?? $campaign->created_at)->format('M j, Y') }}</p>
        @endif

        <ul class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-text-1">
            <li>Queued: {{ $counts['queued'] }}</li>
            <li>Sent: {{ $counts['sent'] }}</li>
            <li>Delivered: {{ $counts['delivered'] }}</li>
            <li>Failed: {{ $counts['failed'] + $counts['undelivered'] }}</li>
            <li>Skipped: {{ $counts['skipped'] }}</li>
            <li>Not sent: {{ $counts['not_sent'] }}</li>
        </ul>

        <p class="type-body-sm mt-4 text-text-2">The message as sent:</p>
        <p class="mt-1 rounded-md bg-surface-1 px-3 py-2 text-text-1">{{ $renderedBody }}</p>

        <ul class="mt-4 space-y-1 text-text-1">
            <li>{{ $campaign->segment_count }} segments per customer</li>
            <li>Total cost: {{ '$'.number_format($campaign->metered_cents / 100, 2) }}</li>
            @if ($campaign->not_sent_count > 0)
                <li>Not sent (daily limit): {{ $campaign->not_sent_count }}</li>
            @endif
        </ul>
    </div>

    <div class="mt-4 overflow-hidden rounded-lg border border-line bg-surface-0 shadow-e1">
        <div class="overflow-x-auto"><table class="tbl tbl-sticky tbl-stack">
            <thead>
                <tr>
                    <th scope="col">Customer</th>
                    <th scope="col">Phone</th>
                    <th scope="col">Status</th>
                    <th scope="col">What happened</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recipients as $recipient)
                    <tr>
                        <td>{{ $recipient->customer->first_name }} {{ $recipient->customer->last_name }}</td>
                        <td>{{ $mask($recipient->phone_e164) }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $recipient->delivery_state)) }}</td>
                        <td>
                            @if (in_array($recipient->delivery_state, ['failed', 'undelivered'], true))
                                {{ $failureReason($recipient->provider_error_code) }}
                            @elseif ($recipient->delivery_state === 'skipped')
                                {{ match ($recipient->skip_reason) {
                                    'opted_out' => 'This number opted out before the send.',
                                    'consent_revoked' => 'This customer no longer agrees to messages.',
                                    'membership_removed' => 'This customer was removed before the send.',
                                    default => 'Not sent.',
                                } }}
                            @elseif ($recipient->delivery_state === 'not_sent')
                                Over the daily limit.
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
        <div class="px-4 py-3">{{ $recipients->links() }}</div>
    </div>
@endsection
