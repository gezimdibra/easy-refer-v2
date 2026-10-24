@extends('layouts.app')

@section('title', 'Value')

@section('notices_summary_only', '1')

@section('content')
    <h1 class="type-title-2 text-text-1">Value</h1>

    <nav aria-label="Breadcrumb" class="type-caption mt-2 text-text-3">
        <a class="text-text-2 hover:text-text-1" href="{{ route('settings') }}">Settings</a>
        <span aria-hidden="true"> / </span>
        <span aria-current="page" class="text-text-1">Value</span>
    </nav>

    <div class="mt-6 lg:flex lg:items-start lg:gap-8">
        <div class="lg:w-[220px] lg:flex-none">
            @include('owner.settings.nav')
        </div>
        <div class="mt-6 min-w-0 flex-1 lg:mt-0">

    <form method="POST" action="{{ route('settings.value.update') }}" class="panel [&_input]:max-w-[560px] [&_select]:max-w-[560px] [&_textarea]:max-w-[560px]" data-loading>
        @csrf

        <label for="average_ticket">Average visit value ($)</label>
        <input id="average_ticket" name="average_ticket" type="text" inputmode="decimal"
            value="{{ old('average_ticket', $business->average_ticket_cents !== null ? number_format($business->average_ticket_cents / 100, 2, '.', '') : '') }}" required
            aria-invalid="{{ $errors->has('average_ticket') ? 'true' : 'false' }}"
            @if ($errors->has('average_ticket')) aria-describedby="average_ticket-error average_ticket-help" @else aria-describedby="average_ticket-help" @endif>
        @error('average_ticket') <p class="form-error" id="average_ticket-error">{{ $message }}</p> @enderror
        <p class="form-help" id="average_ticket-help">Your best guess is fine. You can change it any time, and changing it never changes what you are charged.</p>

        <p class="mt-4"><button type="submit" class="btn btn-primary">Save changes</button></p>
    </form>
        </div>
    </div>
@endsection
