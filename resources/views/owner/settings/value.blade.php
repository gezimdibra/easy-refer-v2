@extends('layouts.app')

@section('title', 'Value')

@section('notices_summary_only', '1')

@section('content')
    <h1 class="text-2xl font-bold">Value</h1>

    @include('owner.settings.nav')

    <form method="POST" action="{{ route('settings.value.update') }}" class="card">
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
@endsection
