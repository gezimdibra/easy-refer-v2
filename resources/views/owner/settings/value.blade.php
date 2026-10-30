@extends('layouts.app')

@section('title', 'Value')


@section('content')
    <h1 class="type-title-2 text-text-1">Value</h1>

    <p class="type-body mt-2 max-w-[68ch] text-text-2">About what a customer spends on one visit at your store. Your Overview uses it to estimate the sales your visits brought in.</p>

    <div class="mt-6 lg:flex lg:items-start lg:gap-8">
        <div class="lg:w-[220px] lg:flex-none">
            @include('owner.settings.nav')
        </div>
        <div class="mt-6 min-w-0 flex-1 lg:mt-0">

    <form method="POST" action="{{ route('settings.value.update') }}" class="panel [&_input]:max-w-[560px] [&_select]:max-w-[560px] [&_textarea]:max-w-[560px]" data-loading
        x-data="{ ticket: @js(old('average_ticket', $business->hasTypicalSpend() ? number_format($business->average_ticket_cents / 100, 2, '.', '') : '')) }">
        @csrf

        <h2 class="type-title-3 text-text-1">How much does a typical customer spend in one visit?</h2>
        <label for="average_ticket" class="mt-4">Typical spend per visit ($)</label>
        <input id="average_ticket" name="average_ticket" type="text" inputmode="decimal" x-model="ticket"
            value="{{ old('average_ticket', $business->hasTypicalSpend() ? number_format($business->average_ticket_cents / 100, 2, '.', '') : '') }}" required
            aria-invalid="{{ $errors->has('average_ticket') ? 'true' : 'false' }}"
            @if ($errors->has('average_ticket')) aria-describedby="average_ticket-error average_ticket-help" @else aria-describedby="average_ticket-help" @endif>
        @error('average_ticket') <p class="form-error" id="average_ticket-error">{{ $message }}</p> @enderror
        <p class="form-help" id="average_ticket-help">Your best guess is fine. You can change it any time, and changing it never changes what you are charged.</p>

        {{-- R2 SET-VALUE (design section 12): the worked example,
             bound live to the field value. The server render
             carries the current value; Alpine keeps it live. --}}
        @if ($business->hasTypicalSpend())
            <p class="type-body-sm mt-3 text-text-1" data-value-example>At {{ '$'.number_format($business->average_ticket_cents / 100, 0) }} a visit, 10 visits estimate about {{ '$'.number_format($business->average_ticket_cents * 10 / 100, 0) }} in estimated sales on your Overview.</p>
        @else
            <p class="type-body-sm mt-3 text-text-1" data-value-example x-show="ticket !== ''" x-cloak>At $<span x-text="ticket"></span> a visit, 10 visits estimate about $<span x-text="(parseFloat(ticket || 0) * 10).toFixed(0)"></span> in estimated sales on your Overview.</p>
        @endif

        <p class="mt-4"><button type="submit" class="btn btn-primary">Save changes</button></p>
    </form>
        </div>
    </div>
@endsection
