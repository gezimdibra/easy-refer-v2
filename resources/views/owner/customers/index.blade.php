@extends('layouts.app')

@section('title', 'Customers')

@section('content')
    {{-- Customers (spec Section 5.2): this business's memberships
         only. Phone renders masked, always (Section 7.3): the
         owner works with names and last fours. --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold">Customers</h1>
        <a class="btn btn-primary" href="{{ route('customers.create') }}">Add a customer</a>
    </div>

    <form method="GET" action="{{ route('customers.index') }}" class="card mt-4">
        <label for="q">Search by name or phone</label>
        <div class="flex gap-2">
            <input id="q" name="q" type="search" value="{{ $search }}" class="flex-1">
            <button type="submit" class="btn btn-secondary">Search</button>
        </div>
    </form>

    <div class="card mt-4">
        @if ($memberships->isEmpty())
            @if ($search !== '')
                <p class="text-text-2">No customers match that search.</p>
                <p class="mt-2"><a class="btn btn-secondary" href="{{ route('customers.index') }}">Clear search</a></p>
            @else
                <p class="text-text-2">No customers yet.</p>
            @endif
        @else
            <table class="w-full text-left text-base">
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="py-2 pr-3 font-semibold">Customer</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Phone</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Joined</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Referrals made</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Rewards earned</th>
                        <th scope="col" class="py-2 font-semibold">Texts</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($memberships as $membership)
                        <tr class="border-b border-line">
                            <td class="py-2 pr-3">
                                <a class="font-semibold text-brand-700 hover:underline" href="{{ route('customers.show', $membership) }}">{{ $membership->customer->first_name }} {{ $membership->customer->last_name }}</a>
                                @if ($membership->consent_source === 'owner_asserted')
                                    <span class="block text-sm text-text-2">Added by you, waiting for their confirmation</span>
                                @endif
                            </td>
                            <td class="py-2 pr-3">{{ \App\Support\PhoneNumber::mask($membership->customer->phone_e164) }}</td>
                            <td class="py-2 pr-3">{{ $membership->created_at->format('M j, Y') }}</td>
                            <td class="py-2 pr-3">{{ $membership->referrals_made_count }}</td>
                            <td class="py-2 pr-3">{{ $membership->rewards_count }}</td>
                            <td class="py-2">
                                @if ($membership->sms_consent)
                                    <span class="pill pill-success">OK to text</span>
                                @else
                                    <span class="pill pill-neutral">No texts</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-3">{{ $memberships->links() }}</div>
        @endif
    </div>
@endsection
