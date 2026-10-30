@extends('layouts.onboarding')

@section('title', 'Get your poster')

@section('content')
    @include('owner.onboarding._progress')

    {{-- OB-4 Poster (design pack Section 4.5 + OB-U5): choose a
         spot, see the real sheet for it (the MIX-1 preview
         rendering: the poster view itself, scaled), print it.
         When the flow is not active the not-active heads-up
         rides along (the QR-INACTIVE-01 sentence's one partial). --}}
    <h1 class="type-title-2 text-text-1">Where will customers see it?</h1>
    <p class="type-body mt-2 text-text-2">Pick the spot. We will make the poster for that spot. Each spot has its own code, so you can see which one brings people in.</p>

    @if ($done)
        <div class="panel mt-6">
            <p class="text-text-1"><span class="font-semibold">✓ Poster taken.</span></p>
            <p class="mt-2 text-text-2">You can print more copies, or another spot, any time from your QR page.</p>
            <p class="mt-4"><a class="btn btn-primary" href="{{ route('onboarding.step', $next) }}">Continue</a></p>
        </div>
    @endif

    @unless ($flowActive)
        <div class="mt-6">
            @include('partials.qr-inactive-notice')
            @include('partials.print-inactive-warning')
        </div>
    @endunless

    <div class="mt-6" x-data="{ spot: 'counter' }">
        <div class="space-y-3" role="radiogroup" aria-label="Poster spot">
            @foreach ($placements as $placement)
                <button type="button" role="radio" :aria-checked="spot === '{{ $placement['key'] }}' ? 'true' : 'false'"
                    @click="spot = '{{ $placement['key'] }}'"
                    class="panel block w-full text-left" :class="spot === '{{ $placement['key'] }}' ? 'border-accent' : ''">
                    <span class="type-body font-semibold text-text-1">{{ ucfirst($placement['key']) }}</span>
                    <span class="type-body mt-1 block text-text-2">{{ $placement['line'] }}</span>
                </button>
            @endforeach
        </div>

        @foreach ($placements as $placement)
            <div class="mt-6" x-show="spot === '{{ $placement['key'] }}'" @if ($placement['key'] !== 'counter') x-cloak @endif>
                <div class="mx-auto overflow-hidden rounded-card border border-border bg-surface" style="width: 190px; height: 269px;" aria-hidden="true">
                    <iframe src="{{ route('owner.qr.poster', ['variant' => $placement['variant'], 'preview' => 1]) }}" title="" tabindex="-1"
                        style="width: 559px; height: 791px; border: 0; transform: scale(0.34); transform-origin: top left; pointer-events: none;"></iframe>
                </div>
                <p class="mt-6 flex flex-wrap items-center gap-4">
                    <a class="btn btn-primary" href="{{ route('owner.qr.poster', ['variant' => $placement['variant'], 'print' => 1]) }}">Print this poster</a>
                    <a class="text-action" href="{{ route('owner.qr.poster', ['variant' => $placement['variant']]) }}">Save it instead</a>
                </p>
            </div>
        @endforeach
    </div>

    <p class="mt-6">
        <a class="text-action" href="{{ route('onboarding.step', $next) }}">Skip this step</a>
    </p>
@endsection
