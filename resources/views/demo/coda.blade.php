@extends('layouts.demo')

@section('title', 'What the owner sees | Easy Refer')
@section('flow_label', 'Demo')

@section('banner')
    @include('demo._banner', ['business' => $business, 'exitStep' => null])
@endsection

@section('content')
    {{-- S6, the owner-side coda (Section 7, verbatim register):
         what the business got, what it costs (promises register
         lines only), the pitch, the mirror, the CTA. The owner
         card renders from the demo visit the prospect just
         made: their own action's data, never seeded data. --}}
    <h1 class="type-display-xs">A new customer just walked in.</h1>

    <p class="mt-3 text-text-2">That was you, playing a customer at @include('demo._name', ['business' => $business]). Its owner would see this visit on their page:</p>

    <div class="well mt-4">
        <p class="flex items-baseline justify-between gap-3">
            <span class="type-caption text-text-2">Shop</span>
            <span class="font-medium text-text-1"><span id="demo-mirror-name">@include('demo._name', ['business' => $business])</span></span>
        </p>
        <p class="mt-2 flex items-baseline justify-between gap-3">
            <span class="type-caption text-text-2">Visit</span>
            <span class="font-medium text-text-1">New customer, first visit</span>
        </p>
        <p class="mt-2 flex items-baseline justify-between gap-3">
            <span class="type-caption text-text-2">Reward</span>
            <span class="font-medium text-text-1">Reward given at the counter</span>
        </p>
        <p class="mt-2 flex items-baseline justify-between gap-3">
            <span class="type-caption text-text-2">Visit counted</span>
            <span class="font-medium text-text-1">Yes</span>
        </p>
    </div>

    <div class="mt-5">
        <p class="text-text-1">Your first 5 visits are free.</p>
        <p class="mt-2 text-text-1">After that, a visit like this one costs $2, only when a friend walks in and uses their reward.</p>
        <p class="mt-2 text-text-1">Never more than $99 a month in visit charges.</p>
        <p class="type-caption mt-2 text-text-2">No monthly fee. No setup fee. Cancel anytime.</p>
    </div>

    <p class="type-title-2 mt-6 text-text-1">I bring you customers in.</p>
    <p class="mt-2 text-text-2">And that customer leaves with their own link, so one visit can send the next friend in.</p>

    <form method="POST" action="{{ route('demo.exit') }}" class="mt-6" id="demo-coda-form">
        @csrf
        <input type="hidden" name="from" value="coda">

        <div class="field">
            <label class="form-label" for="shop_name">What's your shop called?</label>
            <input class="form-input form-input-flow" id="shop_name" name="shop_name" type="text" autocomplete="organization" maxlength="120">
            <p class="form-help">A preview: nothing is saved until you set up.</p>
        </div>

        <div class="mt-4 flex flex-wrap gap-2" role="group" aria-label="Your kind of shop">
            @foreach (['barber' => 'Barber', 'cafe' => 'Café', 'salon' => 'Salon', 'pizza' => 'Pizza', 'other' => 'Other'] as $value => $label)
                <label class="btn btn-secondary btn-sm">
                    <input type="radio" name="trade" value="{{ $value }}" class="sr-only demo-trade-chip">
                    <span>{{ $label }}</span>
                </label>
            @endforeach
        </div>
    </form>

    <form method="POST" action="{{ route('demo.start') }}" class="mt-3 text-center">
        @csrf
        <input type="hidden" name="entry_point" value="block">
        <button type="submit" class="btn btn-ghost btn-sm">Run the demo again</button>
    </form>

    <p class="type-caption mt-6 text-text-2">Juniper Café isn't a real shop, and this visit wasn't a real visit. It was a demo of the real Easy Refer flow.</p>

    <script>
        (function () {
            var input = document.getElementById('shop_name');
            var target = document.getElementById('demo-mirror-name');
            var original = target.textContent;
            input.addEventListener('input', function () {
                var name = input.value.trim();
                target.textContent = name === '' ? original : name;
            });
        })();
    </script>
@endsection

@section('action')
    <button type="submit" form="demo-coda-form" class="btn btn-flow btn-primary w-full">Set up my shop</button>
@endsection
