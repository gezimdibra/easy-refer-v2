@extends('layouts.app')

@section('title', 'Campaigns')

@section('content')
    {{-- Campaigns (spec Section 10.3): the owner's bulk text
         history. Drafts resume at their confirm screen; a sent
         campaign is a billing record and is never deletable. --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="type-title-2 text-text-1">Campaigns</h1>
        @if ($available)
            <a class="btn btn-primary" href="{{ route('campaigns.create') }}">New text campaign</a>
        @endif
    </div>

    @if (! $available)
        @include('partials.campaigns-unavailable')
    @endif

    @if ($campaigns->isEmpty())
        <div class="mt-4 flex flex-col items-center rounded-lg border border-line bg-surface-0 px-6 py-10 text-center shadow-e1">
            <span class="medallion"><x-icon name="message-circle" :size="24" /></span>
            <p class="type-title-3 mt-4 text-text-1">No campaigns yet.</p>
            <p class="mt-2 text-text-2">Text your customers who agreed to receive messages, all at once.</p>
        </div>
    @else
        {{-- R2 CAMP-IDX (design section 1, pick V1): each campaign
             is a card. The message is the title, the status is a
             pill, and every number sits in a labeled tile, so
             nothing loses its label on a phone. The Skipped tile
             (FE-CAMP-01) keeps the accounting complete:
             delivered + failed + skipped = recipients. --}}
        <div class="mt-4 space-y-4">
            @foreach ($campaigns as $campaign)
                @php($statusPill = match ($campaign->status) {
                    'draft' => 'pill-warning',
                    'sending' => 'pill-info',
                    'sent' => 'pill-success',
                    'failed' => 'pill-error',
                    default => 'pill-neutral',
                })
                <article class="panel {{ $campaign->status === 'draft' ? 'border-l-4 border-l-warning-strong' : '' }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <p class="type-title-3 min-w-0 text-text-1">{{ \Illuminate\Support\Str::limit($campaign->body, 60) }}</p>
                        @if ($campaign->isRecognition())
                            {{-- TR-10 (ID-02): the provenance label. --}}
                            <p class="type-body-sm mt-1 text-text-2">Top referrer thank-you</p>
                        @endif
                        <span class="pill {{ $statusPill }}">{{ ucfirst($campaign->status) }}</span>
                    </div>
                    <p class="type-body-sm mt-1 text-text-2">{{ $campaign->status === 'draft' ? 'Started' : 'Sent' }} {{ $business->localTime($campaign->sent_at ?? $campaign->created_at)->format('M j, Y') }}</p>
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        <div class="rounded-md bg-surface-1 px-3 py-2">
                            <p class="tile-label">Recipients</p>
                            <p class="tile-value-sm mt-1">{{ $campaign->recipient_count }}</p>
                        </div>
                        <div class="rounded-md bg-surface-1 px-3 py-2">
                            <p class="tile-label">Delivered</p>
                            <p class="tile-value-sm mt-1">{{ $campaign->delivered_count }}</p>
                        </div>
                        <div class="rounded-md bg-surface-1 px-3 py-2">
                            <p class="tile-label">Failed</p>
                            <p class="tile-value-sm mt-1 {{ $campaign->failed_count > 0 ? 'text-error-text' : '' }}">{{ $campaign->failed_count }}</p>
                        </div>
                        <div class="rounded-md bg-surface-1 px-3 py-2">
                            <p class="tile-label">Skipped</p>
                            <p class="tile-value-sm mt-1">{{ $campaign->skipped_count }}</p>
                        </div>
                        <div class="rounded-md bg-surface-1 px-3 py-2">
                            <p class="tile-label">Cost</p>
                            <p class="tile-value-sm mt-1">{{ '$'.number_format($campaign->metered_cents / 100, 2) }}</p>
                        </div>
                    </div>
                    <div class="mt-4">
                        @if ($campaign->status === 'draft')
                            <span class="flex flex-wrap gap-2">
                                <a class="btn btn-secondary" href="{{ route('campaigns.confirm', $campaign) }}">Review and send</a>
                                <form method="POST" action="{{ route('campaigns.destroy', $campaign) }}" data-loading>
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-ghost">Discard</button>
                                </form>
                            </span>
                        @else
                            <span class="flex flex-wrap gap-2">
                                <a class="btn btn-secondary" href="{{ route('campaigns.show', $campaign) }}">View</a>
                                @if ($campaign->status === 'sent' && ! $campaign->isRecognition())
                                    {{-- ID-02: duplicate never renders for a recognition campaign (TR-11 guardrail 3). --}}
                                    <form method="POST" action="{{ route('campaigns.duplicate', $campaign) }}" data-loading>
                                        @csrf
                                        <button type="submit" class="btn btn-ghost">Send something like this again</button>
                                    </form>
                                @endif
                            </span>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
        <div class="mt-4">{{ $campaigns->links() }}</div>
    @endif
@endsection
