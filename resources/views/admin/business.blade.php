@extends('layouts.admin')

@section('title', 'Business detail')

@section('notices_summary_only', '1')
@section('notices_banner_keys', 'suspend')

@section('content')
    <p><a class="font-semibold text-link hover:underline" href="{{ route('admin.businesses') }}">Back to businesses</a></p>

    <div class="card mt-3">
        <h1 class="text-2xl font-bold">{{ $business->name }}</h1>
        <p class="mt-1 text-text-2">
            @if ($business->status === 'active') <span class="pill pill-success">Active</span>
            @elseif ($business->status === 'disabled') <span class="pill pill-warning">Paused</span>
            @else <span class="pill pill-neutral">Closed</span> @endif
            · {{ $planName ?? 'Pay Per Visit' }} · Joined {{ $business->created_at->format('M j, Y') }}
        </p>
    </div>

    <div class="card mt-4">
        <h2 class="text-xl font-semibold">Owner</h2>
        <p class="mt-1">{{ $business->owner?->name }} · {{ $business->owner?->email }}</p>
        <p class="text-text-2">Contact: {{ $business->contact_email }} · {{ $business->contact_phone_e164 ? \App\Support\PhoneNumber::formatForDisplay($business->contact_phone_e164) : '' }}</p>
    </div>

    <div class="card mt-4">
        <h2 class="text-xl font-semibold">Usage</h2>
        <ul class="mt-2 space-y-1 text-base">
            <li>Customers: {{ $usage['customers'] }}</li>
            <li>Referrals completed: {{ $usage['referrals_completed'] }}</li>
            <li>Referrals pending: {{ $usage['referrals_pending'] }}</li>
            <li>Rewards issued: {{ $usage['rewards_issued'] }}</li>
            <li>Rewards claimed: {{ $usage['rewards_claimed'] }}</li>
            <li>Open holds: {{ $usage['open_holds'] }}</li>
        </ul>
    </div>

    <div class="card mt-4">
        <h2 class="text-xl font-semibold">Billing events</h2>
        <ul class="mt-2 space-y-1 text-base">
            <li>Verified visits (billable): {{ $billingCounts['billable'] }}</li>
            <li>Free visits used: {{ $billingCounts['free'] }}</li>
            <li>Charges: {{ $billingCounts['charges'] }}</li>
            <li>Refunds: {{ $billingCounts['refunds'] }}</li>
            <li>Rewards clawed back: {{ $billingCounts['clawbacks'] }}</li>
        </ul>
    </div>

    @if ($business->status === 'active' || $business->status === 'disabled')
        <div class="card mt-4">
            @if ($business->status === 'active')
                <h2 class="text-xl font-semibold">Pause this business</h2>
                <p class="mt-1 text-text-2">Pausing stops new enrollments and the QR landing page. Visits already on their way can still finish.</p>
                <form method="POST" action="{{ route('admin.businesses.suspend', $business) }}" class="mt-3">
                    @csrf
                    <label for="reason">Reason (saved in the audit log)</label>
                    <input id="reason" name="reason" type="text" required
                        aria-invalid="{{ $errors->has('reason') ? 'true' : 'false' }}"
                        @if ($errors->has('reason')) aria-describedby="reason-error" @endif>
                    @error('reason') <p class="form-error" id="reason-error">{{ $message }}</p> @enderror
                    <p class="mt-3"><button type="submit" class="btn btn-danger">Pause this business</button></p>
                </form>
            @else
                <h2 class="text-xl font-semibold">Reinstate this business</h2>
                <form method="POST" action="{{ route('admin.businesses.reinstate', $business) }}" class="mt-3">
                    @csrf
                    <label for="reason">Reason (saved in the audit log)</label>
                    <input id="reason" name="reason" type="text" required
                        aria-invalid="{{ $errors->has('reason') ? 'true' : 'false' }}"
                        @if ($errors->has('reason')) aria-describedby="reason-error" @endif>
                    @error('reason') <p class="form-error" id="reason-error">{{ $message }}</p> @enderror
                    <p class="mt-3"><button type="submit" class="btn btn-primary">Reinstate this business</button></p>
                </form>
            @endif
        </div>
    @endif
@endsection
