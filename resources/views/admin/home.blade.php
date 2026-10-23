@extends('layouts.admin')

@section('title', 'Platform console')

@section('content')
    <h1 class="text-[28px] font-bold">Platform console</h1>

    @if (session('two_factor_recovery_codes'))
        <div class="gate mt-4">
            <p><strong>Your recovery codes.</strong> Save these somewhere safe. Each one works once, in place of an authenticator code, and they will not be shown again.</p>
            <ul>
                @foreach (session('two_factor_recovery_codes') as $recoveryCode)
                    <li><code>{{ $recoveryCode }}</code></li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card tile">
            <p class="tile-label">Businesses</p>
            <p class="tile-value">{{ $businessTotal }}</p>
        </div>
        <div class="card tile">
            <p class="tile-label">Active</p>
            <p class="tile-value">{{ $businessActive }}</p>
        </div>
        <div class="card tile">
            <p class="tile-label">Paused</p>
            <p class="tile-value">{{ $businessDisabled }}</p>
        </div>
        <div class="card tile">
            <p class="tile-label">Closed</p>
            <p class="tile-value">{{ $businessClosed }}</p>
        </div>
        <div class="card tile">
            <p class="tile-label">New businesses this week</p>
            <p class="tile-value">{{ $businessNewThisWeek }}</p>
        </div>
        <div class="card tile">
            <p class="tile-label">Visits today (platform)</p>
            <p class="tile-value">{{ $visitsToday }}</p>
        </div>
        <div class="card tile">
            <p class="tile-label">Open holds</p>
            <p class="tile-value">{{ $openHolds }}</p>
        </div>
    </div>

    <div class="card mt-4">
        <h2 class="text-xl font-semibold">Recent audit entries</h2>
        @if ($recentAudit->isEmpty())
            <p class="mt-1 text-text-2">No audit entries yet.</p>
        @else
            <table class="mt-2 w-full text-left text-base">
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="py-2 pr-3 font-semibold">When</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Action</th>
                        <th scope="col" class="py-2 font-semibold">Business</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentAudit as $entry)
                        <tr class="border-b border-line">
                            <td class="py-2 pr-3">{{ $entry->created_at->format('M j, Y, g:i A') }}</td>
                            <td class="py-2 pr-3">{{ \App\Support\AuditActionLabel::for($entry->action) }}</td>
                            <td class="py-2">{{ $entry->business?->name }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
