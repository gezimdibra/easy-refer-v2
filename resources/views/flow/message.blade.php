@extends('layouts.flow')

{{-- T6-F2: the does-not-resolve family answers 404, so it takes
     the standard error pages' title; the flow's own 200 states
     keep the plain brand title. FE-INACTIVE-01 refines one case:
     the not_active page (the code resolved to a business whose
     program is not active) names that business in its title —
     the customer is standing in its shop, and naming it ends
     the "is this even the right place?" confusion the
     fresh-eyes auditors flagged on all three businesses. The
     does-not-resolve states (unknown or revoked code) keep the
     generic title and never name a business. --}}
@section('title', in_array($state, ['not_active', 'paused_for_card'], true) && ($business ?? null) !== null ? $business->name.' | Easy Refer' : (in_array($state, ['inactive_qr', 'not_active', 'bad_link', 'paused_for_card'], true) ? 'Page not found | Easy Refer' : 'Easy Refer'))
@section('flow_label', 'Referral')

@section('content')
    {{-- The designed generic states (inventory REF-QR-01, REF-ERR):
         one template, every variant, no technical vocabulary, and
         never a hint about what does or does not exist. R0 states
         the heading once and gives the body its own clause (F-26).
         The locked state (design R3) states the concrete wait and
         keeps its one designed exit (F-05): no dead ends. --}}
    @if ($state === 'inactive_qr')
        <h1 class="type-display-xs">This code is not active</h1>
        <p class="mt-2 text-text-2">Please ask the business for help, or check that you scanned the whole code.</p>
        <a class="btn btn-flow btn-primary w-full mt-5" href="{{ route('home') }}">Go to the homepage</a>
    @elseif ($state === 'not_active')
        <h1 class="type-display-xs">This code is not active</h1>
        @if (($business ?? null) !== null)
            <p class="type-body mt-2 font-semibold text-text-1">{{ $business->name }}</p>
        @endif
        <p class="mt-2 text-text-2">Please ask the business for help, or check that you scanned the whole code.</p>
        <a class="btn btn-flow btn-primary w-full mt-5" href="{{ route('home') }}">Go to the homepage</a>
    @elseif ($state === 'paused_for_card')
        {{-- ACT-PAUSE-FRD-01 (activation redesign, census
             C-34): the allowance pause's own friend page. The
             business is named, the pause is temporary by
             construction, and a reward already claimed is
             stated still good at the counter. It never says
             "not active", and it carries no action for the
             friend, because the friend has none: the remedy
             is the owner's. Valid ONLY when the flow failure's
             block reason is 'allowance_exhausted' (the split
             lives in ReferController::message()). --}}
        <h1 class="type-display-xs">New visits are paused here for now</h1>
        @if (($business ?? null) !== null)
            <p class="type-body mt-2 font-semibold text-text-1">{{ $business->name }}</p>
        @endif
        <p class="mt-2 text-text-2">{{ $business?->name }} has used the free visits that come with its program, so new visits are paused until they set up billing. If you already claimed a reward here, show your code at the counter as usual. Please ask the business for help, or check back soon.</p>
    @elseif ($state === 'bad_link')
        <h1 class="type-display-xs">Something is not right with that link</h1>
        <p class="mt-2 text-text-2">Ask the business or your friend for a new one.</p>
        <a class="btn btn-flow btn-primary w-full mt-5" href="{{ route('home') }}">Go to the homepage</a>
    @elseif ($state === 'rate_limited')
        <h1 class="type-display-xs">Too many tries</h1>
        <p class="mt-2 text-text-2">Too many codes have been requested. Please wait a while and try again.</p>
    @elseif ($state === 'too_many_codes')
        <h1 class="type-display-xs">Too many tries</h1>
        <p class="mt-2 text-text-2">Too many tries. Wait {{ $waitMinutes }} minutes and we will send a fresh code.</p>
    @elseif ($state === 'provider_error')
        <p><span class="medallion"><x-icon name="circle-alert" :size="24" /></span></p>
        <h1 class="type-display-xs mt-4">Something went wrong on our end</h1>
        <p class="type-body mt-2 text-text-2">We could not send your code right now. Your details are saved on the form, so you can pick up right where you left off.</p>
    @endif
@endsection

@if (in_array($state, ['provider_error', 'rate_limited'], true) && ($returnUrl ?? null))
    @section('action')
        {{-- The recovery path (standards G7, visual audit E-2):
             the failure state is never a dead end; the action
             returns to the form or the code screen, with the
             customer's typed details preserved. --}}
        <a class="btn btn-flow btn-primary w-full" href="{{ $returnUrl }}">Try again</a>
    @endsection
@endif

@if ($state === 'too_many_codes' && ($returnUrl ?? null))
    @section('action')
        {{-- R3's locked state keeps exactly one active control:
             "Wrong number?", back to this flow's start. --}}
        <a class="btn btn-flow btn-primary w-full" href="{{ $returnUrl }}">Wrong number?</a>
    @endsection
@endif
