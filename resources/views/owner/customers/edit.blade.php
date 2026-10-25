@extends('layouts.app')

@section('title', 'Edit customer')

@section('notices_summary_only', '1')

@section('content')
    <h1 class="type-title-2 text-text-1">Edit customer</h1>
    <p class="type-body mt-2 max-w-[68ch] text-text-2">Fix a name you typed wrong. The change shows everywhere this customer appears.</p>

    <form method="POST" action="{{ route('customers.update', $membership) }}" class="panel mt-5 [&_input]:max-w-[560px]" data-loading>
        @csrf
        @method('PUT')

        <label for="first_name">First name</label>
        <input id="first_name" name="first_name" type="text" value="{{ old('first_name', $membership->customer->first_name) }}" required autocomplete="given-name"
            aria-invalid="{{ $errors->has('first_name') ? 'true' : 'false' }}"
            @if ($errors->has('first_name')) aria-describedby="first_name-error" @endif>
        @error('first_name') <p class="form-error" id="first_name-error">{{ $message }}</p> @enderror

        <label for="last_name">Last name</label>
        <input id="last_name" name="last_name" type="text" value="{{ old('last_name', $membership->customer->last_name) }}" autocomplete="family-name"
            aria-invalid="{{ $errors->has('last_name') ? 'true' : 'false' }}"
            @if ($errors->has('last_name')) aria-describedby="last_name-error" @endif>
        @error('last_name') <p class="form-error" id="last_name-error">{{ $message }}</p> @enderror

        {{-- The phone is the person's identity key, not an
             attribute (small-gaps design 1.4): it renders as
             text, never an input, with the two real remedies. --}}
        <label>Phone number</label>
        <p class="text-text-1">{{ \App\Support\PhoneNumber::formatForDisplay($membership->customer->phone_e164) }}</p>
        <p class="form-help">Phone numbers can't be changed here, because texts and sign-in codes go to this number. If your customer got a new number, ask them to scan your QR code and enter the new number; we connect it to their account automatically. If you typed the number wrong, remove them from your store and add them again with the right number.</p>

        <p class="mt-6 flex items-center justify-between border-t border-line pt-5">
            <a class="btn btn-ghost" href="{{ route('customers.show', $membership) }}">Cancel</a>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </p>
    </form>
@endsection
