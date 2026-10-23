@extends('layouts.app')

@section('title', 'Your QR code')

@section('content')
    {{-- The QR page (spec Section 5.1, design 3.4, DASH-08): the
         code, the designed poster set with its use labels, the
         facts panel, how redemption works in one sentence, and
         the danger zone with its consequence modal. --}}
    <h1 class="text-[28px] font-bold">Your QR code</h1>
    <p class="mt-1 text-text-2">Customers scan this code to join and start referring.</p>

    <div class="card mt-5 text-center">
        <img src="{{ $imageUrl }}" alt="QR code for {{ $business->name }}" width="280" height="280" class="mx-auto">
        <p class="mt-3 break-all text-text-2">{{ $publicUrl }}</p>
        <form method="POST" action="{{ route('owner.qr.download') }}" class="mt-4">
            @csrf
            <button type="submit" class="btn btn-primary">Download QR code</button>
        </form>
    </div>

    <div class="card mt-4" id="posters">
        <h2 class="text-xl font-semibold">Posters</h2>
        <ul class="mt-3 space-y-3">
            <li class="flex flex-wrap items-center justify-between gap-2">
                <span>For a screen or tablet</span>
                <a class="btn btn-secondary" href="{{ route('owner.qr.poster', 'screen') }}">Download</a>
            </li>
            <li class="flex flex-wrap items-center justify-between gap-2">
                <span>For the counter, A5</span>
                <a class="btn btn-secondary" href="{{ route('owner.qr.poster', 'counter') }}">Download</a>
            </li>
            <li class="flex flex-wrap items-center justify-between gap-2">
                <span>Full page, for the window</span>
                <a class="btn btn-secondary" href="{{ route('owner.qr.poster', 'window') }}">Download</a>
            </li>
        </ul>
        <p class="mt-3">
            <a class="btn btn-secondary" href="{{ route('owner.qr.poster', ['variant' => 'counter', 'print' => 1]) }}">Print poster</a>
        </p>
    </div>

    <div class="card mt-4">
        <h2 class="text-xl font-semibold">About this code</h2>
        <ul class="mt-2 space-y-1 text-base">
            <li>This is your store's only active code.</li>
            <li>Created {{ $qr->created_at->format('F j, Y') }}.</li>
        </ul>
    </div>

    <div class="card mt-4" id="how-redemption-works">
        <h2 class="text-xl font-semibold">How redemption works</h2>
        <p class="mt-1 text-base">A customer shows their reward code. Any staff member scans it with their own phone and taps Mark as Redeemed. No app, no login, nothing to type.</p>
    </div>

    <div class="card-danger mt-4">
        <h2 class="text-xl font-semibold">Replace this code</h2>
        <div class="mt-3">
            <x-confirm-modal
                :action="route('owner.qr.regenerate')"
                id="regenerate-code"
                title="Replace this code?"
                confirm-label="Regenerate code"
                confirm-class="btn-danger">
                <x-slot:trigger>Replace this code</x-slot:trigger>
                <p>Regenerating creates a new QR code. Previously printed codes will stop working. Continue?</p>
                <p class="mt-2 font-semibold">Printed copies of the current code will stop working right away.</p>
            </x-confirm-modal>
        </div>
    </div>
@endsection
