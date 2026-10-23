@extends('layouts.admin')

@section('title', 'Risk feed')

@section('content')
    <h1 class="text-2xl font-bold">Risk feed</h1>

    <form method="GET" action="{{ route('admin.risk-feed') }}" class="card mt-4">
        <label for="q">Search by phone, business, or rule</label>
        <div class="flex gap-2">
            <input id="q" name="q" type="search" value="{{ $search }}" class="flex-1">
            <button type="submit" class="btn btn-secondary">Search</button>
        </div>
    </form>

    <div class="card mt-4">
        @if ($events->isEmpty())
            @if ($search !== '')
                <p class="text-text-2">No risk events match.</p>
            @else
                <p class="text-text-2">No risk events yet.</p>
            @endif
        @else
            <table class="w-full text-left text-base">
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="py-2 pr-3 font-semibold">When</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Business</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Event</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Rule</th>
                        <th scope="col" class="py-2 font-semibold">Phone</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($events as $event)
                        <tr class="border-b border-line">
                            <td class="py-2 pr-3">{{ $event->created_at->format('M j, Y, g:i A') }}</td>
                            <td class="py-2 pr-3">{{ $event->business?->name }}</td>
                            <td class="py-2 pr-3">{{ \App\Support\ReferralTimeline::label($event->event_type) }}</td>
                            <td class="py-2 pr-3">{{ implode(' ', \App\Support\HoldReasons::sentences((array) ($event->metadata['rules'] ?? $event->referral?->hold_rules ?? []), $event->business)) }}</td>
                            <td class="py-2">{{ $event->referral?->referredCustomer?->phone_e164 ? \App\Support\PhoneNumber::mask($event->referral->referredCustomer->phone_e164) : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-3">{{ $events->links() }}</div>
        @endif
    </div>
@endsection
