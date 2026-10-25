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
        <div class="panel mt-5">
            <p class="text-text-1">Text campaigns are not available right now.</p>
        </div>
    @endif

    <div class="mt-4 overflow-hidden rounded-lg border border-line bg-surface-0 shadow-e1">
        @if ($campaigns->isEmpty())
            <div class="flex flex-col items-center px-6 py-10 text-center">
                <span class="medallion"><x-icon name="message-circle" :size="24" /></span>
                <p class="type-title-3 mt-4 text-text-1">No campaigns yet.</p>
                <p class="mt-2 text-text-2">Text your customers who agreed to receive messages, all at once.</p>
            </div>
        @else
            <div class="overflow-x-auto"><table class="tbl tbl-sticky tbl-stack">
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Message</th>
                        <th scope="col">Recipients</th>
                        <th scope="col">Delivered</th>
                        <th scope="col">Failed</th>
                        <th scope="col">Cost</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($campaigns as $campaign)
                        <tr>
                            <td>{{ ($campaign->sent_at ?? $campaign->created_at)->format('M j, Y') }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($campaign->body, 60) }}</td>
                            <td>{{ $campaign->recipient_count }}</td>
                            <td>{{ $campaign->delivered_count }}</td>
                            <td>{{ $campaign->failed_count }}</td>
                            <td>{{ '$'.number_format($campaign->metered_cents / 100, 2) }}</td>
                            <td>{{ ucfirst($campaign->status) }}</td>
                            <td>
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
                                    <a class="btn btn-secondary" href="{{ route('campaigns.show', $campaign) }}">View</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div class="px-4 py-3">{{ $campaigns->links() }}</div>
        @endif
    </div>
@endsection
