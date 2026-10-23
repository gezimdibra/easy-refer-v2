@extends('layouts.app')

@section('title', 'Add a customer')

@section('notices_summary_only', '1')

@section('content')
    <h1 class="text-2xl font-bold">Add a customer</h1>
    <p class="mt-1 text-text-2">Add a regular who agreed to get their referral link by text. We send one text so they can confirm.</p>

    <form method="POST" action="{{ route('customers.store') }}" class="card mt-4">
        @csrf

        <label for="first_name">First name</label>
        <input id="first_name" name="first_name" type="text" value="{{ old('first_name') }}" required autocomplete="given-name"
            aria-invalid="{{ $errors->has('first_name') ? 'true' : 'false' }}"
            @if ($errors->has('first_name')) aria-describedby="first_name-error" @endif>
        @error('first_name') <p class="form-error" id="first_name-error">{{ $message }}</p> @enderror

        <label for="last_name">Last name</label>
        <input id="last_name" name="last_name" type="text" value="{{ old('last_name') }}" autocomplete="family-name"
            aria-invalid="{{ $errors->has('last_name') ? 'true' : 'false' }}"
            @if ($errors->has('last_name')) aria-describedby="last_name-error" @endif>
        @error('last_name') <p class="form-error" id="last_name-error">{{ $message }}</p> @enderror

        <label for="phone">Phone number</label>
        <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" required autocomplete="tel"
            aria-invalid="{{ $errors->has('phone') ? 'true' : 'false' }}"
            @if ($errors->has('phone')) aria-describedby="phone-error" @endif>
        @error('phone') <p class="form-error" id="phone-error">{{ $message }}</p> @enderror

        <label class="mt-4 flex items-start gap-3">
            <input type="checkbox" name="consent" value="1" @checked(old('consent')) class="mt-1">
            <span>Agreed to receive text messages</span>
        </label>

        <p class="mt-4">
            <button type="submit" class="btn btn-primary">Add a customer</button>
            <a class="btn btn-ghost" href="{{ route('customers.index') }}">Cancel</a>
        </p>
    </form>
@endsection
