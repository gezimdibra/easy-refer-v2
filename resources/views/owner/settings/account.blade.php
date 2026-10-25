@extends('layouts.app')

@section('title', 'Account')

@section('notices_summary_only', '1')

@section('content')
    <h1 class="type-title-2 text-text-1">Account</h1>

    <nav aria-label="Breadcrumb" class="type-caption mt-2 text-text-2">
        <a class="text-text-2 hover:text-text-1" href="{{ route('settings') }}">Settings</a>
        <span aria-hidden="true"> / </span>
        <span aria-current="page" class="text-text-1">Account</span>
    </nav>

    <div class="mt-6 lg:flex lg:items-start lg:gap-8">
        <div class="lg:w-[220px] lg:flex-none">
            @include('owner.settings.nav')
        </div>
        <div class="mt-6 min-w-0 flex-1 lg:mt-0">

    <div class="panel">
        <h2 class="type-title-3 text-text-1">Close your account</h2>
        @if ($business->isClosed())
            <p class="mt-1 text-text-2">Your account closed on {{ $business->closed_at?->format('M j, Y') }}. You can still sign in to confirm claims and view your history until {{ $business->closed_at?->copy()->addDays(30)->format('M j, Y') }}.</p>
        @else
            <p class="mt-1">Closing your account voids pending referrals. Minted rewards that are not yet claimed stay claimable for 30 days. Billing stops after the final reconciliation.</p>
            {{-- The confirm stays disabled until the gate is met
                 (design 6.4 matrix, modals row): the checkbox is
                 the gate, and the disabled state is visible in
                 the markup, not only in native validation. --}}
            <form method="POST" action="{{ route('settings.account.close') }}" class="mt-4" x-data="{ confirmed: false }" data-loading>
                @csrf

                <label class="flex items-start gap-3">
                    <input type="checkbox" name="confirm" value="1" required class="mt-1" x-model="confirmed"
                        aria-invalid="{{ $errors->has('confirm') ? 'true' : 'false' }}"
                        @if ($errors->has('confirm')) aria-describedby="confirm-error" @endif>
                    <span>I understand what closing my account does.</span>
                </label>
                @error('confirm') <p class="form-error" id="confirm-error">{{ $message }}</p> @enderror

                <p class="mt-4"><button type="submit" class="btn btn-danger" :disabled="! confirmed">Close account</button></p>
            </form>
        @endif
    </div>
        </div>
    </div>
@endsection
