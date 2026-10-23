@extends('layouts.flow')

@section('title', 'Easy Refer')
@section('flow_label', 'Referral')

@section('content')
    {{-- The designed generic states (inventory REF-QR-01, REF-ERR):
         one template, every variant, no technical vocabulary, and
         never a hint about what does or does not exist. R0 states
         the heading once and gives the body its own clause (F-26).
         The locked state (design R3) states the concrete wait and
         keeps its one designed exit (F-05): no dead ends. --}}
    @if ($state === 'inactive_qr')
        <h1 class="text-[32px] font-bold leading-[38px]">This code is not active</h1>
        <p class="mt-2 text-text-2">Please ask the business for help, or check that you scanned the whole code.</p>
    @elseif ($state === 'not_active')
        <h1 class="text-[32px] font-bold leading-[38px]">This code is not active</h1>
        <p class="mt-2 text-text-2">Please ask the business for help, or check that you scanned the whole code.</p>
    @elseif ($state === 'bad_link')
        <h1 class="text-[32px] font-bold leading-[38px]">Something is not right with that link</h1>
        <p class="mt-2 text-text-2">Ask the business or your friend for a new one.</p>
        <a class="btn btn-flow btn-primary w-full mt-5" href="{{ route('home') }}">Go to the homepage</a>
    @elseif ($state === 'rate_limited')
        <h1 class="text-[32px] font-bold leading-[38px]">Too many tries</h1>
        <p class="mt-2 text-text-2">Too many codes have been requested. Please wait a while and try again.</p>
    @elseif ($state === 'too_many_codes')
        <h1 class="text-[32px] font-bold leading-[38px]">Too many tries</h1>
        <p class="mt-2 text-text-2">Too many tries. Wait {{ $waitMinutes }} minutes and we will send a fresh code.</p>
    @elseif ($state === 'provider_error')
        <h1 class="text-[32px] font-bold leading-[38px]">Something went wrong on our end</h1>
        <p class="mt-2 text-text-2">Something went wrong on our end. Please try again in a moment.</p>
    @endif
@endsection

@if ($state === 'too_many_codes' && ($returnUrl ?? null))
    @section('action')
        {{-- R3's locked state keeps exactly one active control:
             "Wrong number?", back to this flow's start. --}}
        <a class="btn btn-flow btn-primary w-full" href="{{ $returnUrl }}">Wrong number?</a>
    @endsection
@endif
