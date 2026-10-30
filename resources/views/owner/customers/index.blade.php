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
            {{-- CU-1: below md the list is a row grammar, not a
                 table: monogram, name, one meta line carrying the
                 counts and the joined date, the texts pill at the
                 right. The phone leaves the list (the detail page
                 carries the full number, one tap away). --}}
            <ul class="divide-y divide-line md:hidden">
                @foreach ($memberships as $membership)
                    <li class="relative flex items-start gap-3 px-4 py-3">
                        <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-surface-2 text-text-1" aria-hidden="true"><span style="font-size: 13px; font-weight: 650">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($membership->customer->first_name, 0, 1)) }}{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($membership->customer->last_name, 0, 1)) }}</span></span>
                        <span class="min-w-0 flex-1">
                            <a class="font-semibold text-link hover:underline after:absolute after:inset-0" href="{{ route('customers.show', $membership) }}">{{ $membership->customer->fullName() }}</a>
                            <span class="block text-sm text-text-2">{{ $membership->referrals_made_count }} {{ \Illuminate\Support\Str::plural('referral', $membership->referrals_made_count) }} · {{ $membership->rewards_count }} {{ \Illuminate\Support\Str::plural('reward', $membership->rewards_count) }} · joined {{ $business->localTime($membership->created_at)->format('M j, Y') }}</span>
                            @if ($membership->consent_source === 'owner_asserted')
                                <span class="block text-sm text-warning-text" style="font-weight: 550">Added by you, waiting for their confirmation</span>
                            @endif
                        </span>
                        @if ($membership->sms_consent)
                            <span class="pill pill-success flex-none">OK to text</span>
                        @else
                            <span class="pill pill-neutral flex-none">No texts</span>
                        @endif
                    </li>
                @endforeach
            </ul>
            {{-- CU-3: the desktop table stays for cross-row
                 comparison, tightened: compact row padding and
                 the monogram in the name cell. CU-2: the
                 owner-asserted line carries the warning tone at
                 550 weight on both form factors. --}}
            <div class="hidden overflow-x-auto md:block"><table class="tbl tbl-sticky">
                <thead>
                    <tr>
                        <th scope="col" class="py-2">Customer</th>
                        <th scope="col" class="py-2">Phone</th>
                        <th scope="col" class="py-2">Joined</th>
                        <th scope="col" class="num py-2">Referrals made</th>
                        <th scope="col" class="num py-2">Rewards earned</th>
                        <th scope="col" class="py-2">Texts</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($memberships as $membership)
                        <tr>
                            <td class="py-2">
                                <span class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-surface-2 text-text-1" aria-hidden="true"><span style="font-size: 13px; font-weight: 650">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($membership->customer->first_name, 0, 1)) }}{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($membership->customer->last_name, 0, 1)) }}</span></span>
                                    <span>
                                        <a class="font-semibold text-link hover:underline" href="{{ route('customers.show', $membership) }}">{{ $membership->customer->fullName() }}</a>
                                        @if ($membership->consent_source === 'owner_asserted')
                                            <span class="block text-sm text-warning-text" style="font-weight: 550">Added by you, waiting for their confirmation</span>
                                        @endif
                                    </span>
                                </span>
                            </td>
                            <td class="whitespace-nowrap py-2">{{ \App\Support\PhoneNumber::mask($membership->customer->phone_e164) }}</td>
                            <td class="whitespace-nowrap py-2">{{ $business->localTime($membership->created_at)->format('M j, Y') }}</td>
                            <td class="num py-2">{{ $membership->referrals_made_count }}</td>
                            <td class="num py-2">{{ $membership->rewards_count }}</td>
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
            </table></div>
            @if ($memberships->hasPages())
                <div class="border-t border-line px-4 py-3">{{ $memberships->links() }}</div>
            @endif
        @endif
    </div>
@endsection
