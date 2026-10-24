@extends('layouts.app')

@section('title', 'Your QR code')

@section('content')
    {{-- The QR page (spec Section 5.1, design 3.4, DASH-08;
         redesign-spec.md Section 10.5 item 4): the code in a
         framed, centered composition, the designed poster set
         with previews and its use labels, the facts panel, how
         redemption works, and the danger zone with its
         consequence modal. The public link renders as a designed
         copy field under the code, not a naked debug string
         (visual audit Q-1). --}}
    <h1 class="type-title-2 text-text-1">Your QR code</h1>
    <p class="type-body mt-2 text-text-2">Customers scan this code to join and start referring.</p>

    <div class="mx-auto mt-6 max-w-[420px] rounded-xl border border-line bg-surface-0 shadow-e2" style="padding: 32px">
        <img src="{{ $imageUrl }}" alt="QR code for {{ $business->name }}" width="280" height="280" class="mx-auto">
        <form method="POST" action="{{ route('owner.qr.download') }}" class="mt-6 text-center" data-loading>
            @csrf
            <button type="submit" class="btn btn-primary"><x-icon name="download" :size="20" /> Download QR code</button>
        </form>
        {{-- The public link as a designed copy field (the link
             itself is product surface: owners paste it into
             listings and messages; visual audit Q-1 objected to
             the naked debug-style paragraph, not the link). --}}
        <div class="joined mt-6" x-data="{ copied: false }">
            <input type="text" readonly value="{{ $publicUrl }}" class="form-input form-input-app" aria-label="Your public referral link" x-ref="publicUrl">
            <button type="button" class="btn btn-secondary"
                @click="navigator.clipboard.writeText($refs.publicUrl.value); copied = true"
                x-text="copied ? 'Copied' : 'Copy link'">Copy link</button>
        </div>
    </div>

    <div class="panel mt-6" id="posters">
        <h2 class="type-title-3 text-text-1">Posters</h2>
        <ul class="mt-4 space-y-3">
            @foreach ([
                ['screen', 'For a screen or tablet'],
                ['counter', 'For the counter, A5'],
                ['window', 'Full page, for the window'],
            ] as [$variant, $label])
                <li class="flex flex-wrap items-center gap-4">
                    <span class="flex h-14 w-14 flex-none items-center justify-center rounded-lg border border-line bg-surface-0 p-1.5">
                        <img src="{{ $imageUrl }}" alt="" class="h-full w-full">
                    </span>
                    <span class="type-body flex-1 text-text-1">{{ $label }}</span>
                    <a class="btn btn-secondary" href="{{ route('owner.qr.poster', $variant) }}"><x-icon name="download" :size="20" /> Download</a>
                </li>
            @endforeach
        </ul>
        <p class="mt-4">
            <a class="btn btn-secondary" href="{{ route('owner.qr.poster', ['variant' => 'counter', 'print' => 1]) }}"><x-icon name="printer" :size="20" /> Print counter poster</a>
        </p>
    </div>

    <div class="panel mt-4">
        <h2 class="type-title-3 text-text-1">About this code</h2>
        <ul class="mt-2 space-y-1">
            <li class="type-body text-text-2">This is your store's only active code.</li>
            <li class="type-body text-text-2">Created {{ $qr->created_at->format('F j, Y') }}.</li>
        </ul>
    </div>

    <div class="panel mt-4" id="how-redemption-works">
        <h2 class="type-title-3 text-text-1">How redemption works</h2>
        <p class="type-body mt-2 text-text-2">A customer shows their reward code. Any staff member scans it with their own phone and taps Mark as Redeemed. No app, no login, nothing to type.</p>
    </div>

    <div class="card-danger mt-4">
        <h2 class="type-title-3 text-text-1">Replace this code</h2>
        <div class="mt-3">
            <x-confirm-modal
                :action="route('owner.qr.regenerate')"
                id="regenerate-code"
                title="Replace this code?"
                confirm-label="Regenerate code"
                confirm-class="btn-danger">
                <x-slot:trigger><button type="button" class="btn btn-danger-ghost">Replace this code</button></x-slot:trigger>
                <p>Regenerating creates a new QR code. Previously printed codes will stop working. Continue?</p>
                <p class="mt-2 font-semibold">Printed copies of the current code will stop working right away.</p>
            </x-confirm-modal>
        </div>
    </div>
@endsection
