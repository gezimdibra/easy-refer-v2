@extends('layouts.admin')

@section('title', 'Businesses')

@section('content')
    <h1 class="text-2xl font-bold">Businesses</h1>

    <form method="GET" action="{{ route('admin.businesses') }}" class="card mt-4">
        <label for="q">Search by business name, owner email, or phone</label>
        <div class="flex gap-2">
            <input id="q" name="q" type="search" value="{{ $search }}" class="flex-1">
            <button type="submit" class="btn btn-secondary">Search</button>
        </div>
    </form>

    <div class="card mt-4">
        @if ($businesses->isEmpty())
            @if ($search !== '')
                <p class="text-text-2">No businesses match that search.</p>
            @else
                <p class="text-text-2">No businesses yet.</p>
            @endif
        @else
            <table class="w-full text-left text-base">
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="py-2 pr-3 font-semibold">Business</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Owner</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Status</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Plan</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Customers</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Referrals completed</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">How they heard</th>
                        <th scope="col" class="py-2 font-semibold">Joined</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($businesses as $business)
                        <tr class="border-b border-line">
                            <td class="py-2 pr-3"><a class="font-semibold text-link hover:underline" href="{{ route('admin.businesses.show', $business) }}">{{ $business->name }}</a></td>
                            <td class="py-2 pr-3">{{ $business->owner?->email }}</td>
                            <td class="py-2 pr-3">
                                @if ($business->status === 'active') <span class="pill pill-success">Active</span>
                                @elseif ($business->status === 'disabled') <span class="pill pill-warning">Paused</span>
                                @else <span class="pill pill-neutral">Closed</span> @endif
                            </td>
                            <td class="py-2 pr-3">{{ $business->currentPlan?->name ?? 'Pay Per Visit' }}</td>
                            <td class="py-2 pr-3">{{ $business->memberships_count }}</td>
                            <td class="py-2 pr-3">{{ $business->completed_referrals }}</td>
                            <td class="py-2 pr-3">{{ \App\Support\SignupSource::effectiveLabel($business) }}@if ($business->signup_source_detail) <span class="text-text-2">({{ $business->signup_source_detail }})</span>@endif</td>
                            <td class="py-2">{{ $business->created_at->format('M j, Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-3">{{ $businesses->links() }}</div>
        @endif
    </div>
@endsection
