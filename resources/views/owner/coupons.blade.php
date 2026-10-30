@extends('layouts.app')

@section('title', 'Coupons')

@section('content')
    {{-- The Coupons tab (ID-11, Amendment 53): the QR page's
         secondary tab. The batch list is the coaching surface
         for coupons (per-batch scans, claims, redemptions,
         derived counts), the creation form approves a prefilled
         offer rather than authoring one, and the AHA block is
         the tab's empty state until the first batch exists. --}}
    <h1 class="type-title-2 text-text-1">Your QR code</h1>
    <p class="type-body mt-2 text-text-2">Customers scan this code to join and start referring.</p>

    @include('partials.coupon-tabs', ['active' => 'coupons'])

    <h2 class="type-title-3 mt-6 text-text-1">Coupons</h2>
    <p class="type-body mt-2 text-text-2">Offers you print and place outside your shop, for people who have not found you yet.</p>

    @if ($banner)
        <div class="gate mt-6">
            <p class="type-body text-text-1">{{ $banner }}</p>
        </div>
    @endif

    @if (session('status'))
        <p class="status status-success mt-4" role="status">{{ session('status') }}</p>
    @endif

    @if (session('coupon_activation_notice'))
        <p class="status status-error mt-4" role="status">{{ session('coupon_activation_notice') }}</p>
    @endif

    @unless ($hasBatches)
        {{-- The 20-second AHA block (AHA-COUP-01/02/03): WHY,
             HOW in two steps, and trade-specific IDEAS. It is
             the tab's empty state; the first batch replaces it
             with the batch list for good. --}}
        <div class="panel mt-6" id="coupon-aha">
            <p class="type-body text-text-2">A referral needs a customer to share it. A coupon does not. You print it, you place it where people already are, and anyone who scans it can claim your offer at your counter. These go outside, not on your counter: their whole job is to fetch people who were not already here.</p>
            <p class="type-body mt-3 text-text-2">1. Tap Create a batch and approve the offer we filled in for you. 2. Print the sheet and put the flyers where you said they would go. That is the whole setup.</p>
            <p class="type-body mt-3 text-text-2">{{ $ideas }}</p>
        </div>
    @endunless

    <div class="panel mt-6" id="new-batch">
        <h3 class="type-title-3 text-text-1">New batch of coupons</h3>

        @include('partials.notices', ['summaryOnly' => true, 'summaryList' => true])

        <form method="POST" action="{{ route('owner.qr.coupons.store') }}" class="mt-4" data-loading x-data="{ naming: {{ $errors->has('label_custom') || old('label_custom') ? 'true' : 'false' }} }">
            @csrf
            <input type="hidden" name="prefill_source" value="{{ $prefill['source'] }}">
            <input type="hidden" name="created_from" value="{{ request('from') === 'hint' ? 'hint' : 'tab' }}">

            <div class="field">
                <label class="form-label" for="offer_text">Your offer</label>
                <input type="text" name="offer_text" id="offer_text" class="form-input form-input-app" value="{{ old('offer_text', $prefill['text']) }}">
                <p class="form-help">
                    @if ($prefill['source'] === 'owner')
                        We filled this in from your current first-visit offer. Change it if this batch should say something different.
                    @elseif ($prefill['source'] === 'pack')
                        We filled this in from the starter offer for shops like yours. Change anything you like.
                    @else
                        We started you with a simple offer. Change anything you like.
                    @endif
                </p>
                @error('offer_text') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="field mt-4">
                <span class="form-label">Where are these going?</span>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($labelChips as $chip)
                        <label class="btn btn-secondary">
                            <input type="radio" name="label_chip" value="{{ $chip }}" class="sr-only" @checked(old('label_chip') === $chip)>
                            <span>{{ $chip }}</span>
                        </label>
                    @endforeach
                </div>
                @error('label_chip') <p class="form-error">{{ $message }}</p> @enderror
                <button type="button" class="type-body mt-2 inline-flex min-h-11 items-center px-2 text-text-2 underline underline-offset-4" @click="naming = true">Name it myself</button>
                <div x-show="naming" x-cloak>
                    <label class="form-label" for="label_custom">Your name for this batch</label>
                    <input type="text" name="label_custom" id="label_custom" class="form-input form-input-app mt-2" value="{{ old('label_custom') }}" maxlength="40">
                    @error('label_custom') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="field mt-4">
                <span class="form-label">How many people can claim it?</span>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($capOptions as $cap)
                        <label class="btn btn-secondary">
                            <input type="radio" name="claim_cap" value="{{ $cap }}" class="sr-only" @checked((int) old('claim_cap', config('coupons.claim_cap_default')) === $cap)>
                            <span>{{ $cap }}</span>
                        </label>
                    @endforeach
                </div>
                @error('claim_cap') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="field mt-4">
                <span class="form-label">How long does it run?</span>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($expiryOptions as $days)
                        <label class="btn btn-secondary">
                            <input type="radio" name="expiry_days" value="{{ $days }}" class="sr-only" @checked((int) old('expiry_days', config('coupons.expiry_days_default')) === $days)>
                            <span>{{ $days }} days</span>
                        </label>
                    @endforeach
                </div>
                @error('expiry_days') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            {{-- [COUP-07]: the liability and billing ruling in
                 one glance, from the form's current state. --}}
            @php
                $reviewCap = (int) old('claim_cap', config('coupons.claim_cap_default'));
                $reviewDays = (int) old('expiry_days', config('coupons.expiry_days_default'));
                $reviewOffer = old('offer_text', $prefill['text']);
            @endphp
            <p class="type-body mt-4 text-text-2" data-coupon-review>Up to {{ $reviewCap }} people can claim this coupon, one each. Ends {{ $business->localTime(now()->addDays($reviewDays))->format('F j, Y') }}. If everyone claims it, you will give {{ $reviewOffer }} up to {{ $reviewCap }} times. Easy Refer charges nothing for coupon claims.</p>

            <p class="mt-4">
                <button type="submit" class="btn btn-primary">Create a batch</button>
            </p>
        </form>
    </div>

    @if ($hasBatches)
        <div class="panel mt-6" id="batch-list">
            <h3 class="type-title-3 text-text-1">Your batches</h3>
            <p class="type-body mt-2 text-text-2">Scans = people who opened the coupon.</p>
            <ul>
                @foreach ($batches as $row)
                    @php($batch = $row['model'])
                    <li class="border-line {{ $loop->first ? '' : 'border-t' }}" style="padding: 14px 0" data-batch="{{ $batch->id }}">
                        <p class="type-body font-semibold text-text-1">{{ $batch->label }}</p>
                        <p class="type-body text-text-2">{{ $batch->offer_text }}</p>
                        <p class="type-body text-text-2">Scans {{ $row['scans'] }} / Claimed {{ $row['claimed'] }} / Redeemed {{ $row['redeemed'] }} / {{ $row['statusWord'] }}@if ($row['daysLeft'] !== null) / {{ $row['daysLeft'] }} {{ $row['daysLeft'] === 1 ? 'day' : 'days' }} left @else / Ends {{ $business->localTime($batch->expires_at)->format('F j, Y') }} @endif</p>
                        @if ($row['zeroLine'])
                            <p class="type-body text-text-2">{{ $row['zeroLine'] }}</p>
                        @endif
                        @if ($row['flagged'])
                            {{-- [COUP-15] (state C11): the batch
                                 flag. It asserts speed, never
                                 fraud, and names an innocent
                                 cause first. --}}
                            <div class="well mt-3">
                                <p class="type-body text-text-1">Worth a look: {{ $row['flagMintsLastHour'] }} people claimed in the last hour, which is much faster than this batch usually goes. If the flyers went somewhere new, that can be normal.</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <form method="POST" action="{{ route('owner.qr.coupons.flag.dismiss', ['batch' => $batch]) }}" data-loading>
                                        @csrf
                                        <button type="submit" class="btn btn-secondary">Looks fine</button>
                                    </form>
                                </div>
                            </div>
                        @endif
                        <div class="mt-2.5 flex flex-wrap items-center gap-2">
                            <a class="btn btn-secondary" href="{{ route('owner.qr.coupons.flyer', ['batch' => $batch, 'print' => 1]) }}">Print the flyer sheet</a>
                            <a class="btn btn-secondary" href="{{ route('owner.qr.coupons.claims', ['batch' => $batch]) }}">See claims</a>
                            @if (in_array($batch->status, ['active', 'full'], true))
                                <x-confirm-modal
                                    :action="route('owner.qr.coupons.kill', ['batch' => $batch])"
                                    id="stop-batch-{{ $batch->id }}"
                                    title="Stop this batch?"
                                    confirm-label="Stop this batch"
                                    confirm-class="btn-danger">
                                    <x-slot:trigger><button type="button" class="type-body inline-flex min-h-11 items-center px-2 text-text-2 underline underline-offset-4">Stop this batch</button></x-slot:trigger>
                                    <p>No one new will be able to claim from {{ $batch->label }} after this. Coupons already claimed stay good until their own dates run out. The flyers themselves will show the offer as ended.</p>
                                </x-confirm-modal>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="type-body mt-4 text-text-2">Coupon claims are never charged. You only pay when a referred customer walks in and redeems.</p>
@endsection
