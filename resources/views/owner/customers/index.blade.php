@extends('layouts.app')

@section('title', 'Customers')

@section('content')
    {{-- Customers (spec Section 5.2): this business's memberships
         only. Phone renders masked, always (Section 7.3): the
         owner works with names and last fours. --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="type-title-2 text-text-1">Customers</h1>
        <a class="btn btn-primary" href="{{ route('customers.create') }}">Add a customer</a>
    </div>

    <form method="GET" action="{{ route('customers.index') }}" class="mt-5" data-loading>
        <label class="form-label" for="q">Search by name or phone</label>
        <div class="flex gap-2">
            <span class="relative flex-1">
                <span class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-text-3"><x-icon name="search" :size="20" /></span>
                <input id="q" name="q" type="search" value="{{ $search }}" class="form-input form-input-app pl-11">
            </span>
            <button type="submit" class="btn btn-secondary">Search</button>
        </div>
    </form>

    <div class="mt-4 overflow-hidden rounded-lg border border-line bg-surface-0 shadow-e1">
        @if ($memberships->isEmpty())
            <div class="flex flex-col items-center px-6 py-10 text-center">
                <span class="medallion"><x-icon name="users" :size="24" /></span>
                @if ($search !== '')
                    {{-- CP-11 search variant: name what was
                         searched, suggest the recovery, and
                         offer the one next action. --}}
                    <p class="type-title-3 mt-4 text-text-1">No customers match that search.</p>
                    <p class="mt-2 text-text-2">Nothing found for "{{ $search }}". Check the spelling, or add them as a new customer.</p>
                    <p class="mt-4 flex flex-wrap justify-center gap-2">
                        <a class="btn btn-primary" href="{{ route('customers.create') }}">Add a customer</a>
                        <a class="btn btn-secondary" href="{{ route('customers.index') }}">Clear search</a>
                    </p>
                @else
                    <p class="type-title-3 mt-4 text-text-1">No customers yet.</p>
                @endif
            </div>
        @else
            <div class="overflow-x-auto"><table class="tbl tbl-sticky tbl-stack">
                <thead>
                    <tr>
                        <th scope="col">Customer</th>
                        <th scope="col">Phone</th>
                        <th scope="col">Joined</th>
                        <th scope="col" class="num">Referrals made</th>
                        <th scope="col" class="num">Rewards earned</th>
                        <th scope="col">Texts</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($memberships as $membership)
                        <tr>
                            <td>
                                <a class="card-link font-semibold text-link hover:underline" href="{{ route('customers.show', $membership) }}">{{ $membership->customer->first_name }} {{ $membership->customer->last_name }}</a>
                                @if ($membership->consent_source === 'owner_asserted')
                                    <span class="block text-sm text-text-2">Added by you, waiting for their confirmation</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap" data-label="Phone">{{ \App\Support\PhoneNumber::mask($membership->customer->phone_e164) }}</td>
                            <td class="whitespace-nowrap" data-label="Joined">{{ $membership->created_at->format('M j, Y') }}</td>
                            <td class="num" data-label="Referrals made">{{ $membership->referrals_made_count }}</td>
                            <td class="num" data-label="Rewards earned">{{ $membership->rewards_count }}</td>
                            <td data-label="Texts">
                                @if ($membership->sms_consent)
                                    <span class="pill pill-success">OK to text</span>
                                @else
                                    <span class="pill pill-neutral">No texts</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div class="border-t border-line px-4 py-3">{{ $memberships->links() }}</div>
        @endif
    </div>
@endsection
