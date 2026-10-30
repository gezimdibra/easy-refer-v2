@extends('layouts.demo')

@section('title', 'Claim your reward | Easy Refer')
@section('flow_label', 'Claim')

@section('banner')
    @include('demo._banner', ['business' => $business, 'exitStep' => 'form'])
@endsection

@section('content')
    {{-- S2, the claim form (Section 3): the real claim form's
         shape and fields (name, phone, email as the shipped
         form presents it, consent as the shipped form asks
         it), plus the Section 5.2 fate line under the phone
         field and the example-number escape. --}}
    <h1 class="type-display-xs">Claim your reward at @include('demo._name', ['business' => $business])</h1>

    @include('partials.notices')

    <form method="POST" action="{{ route('demo.form.submit') }}" class="mt-5" id="demo-claim-form" data-loading>
        @csrf

        <div class="field">
            <label class="form-label" for="first_name">First name</label>
            <input class="form-input form-input-flow" id="first_name" name="first_name" type="text" autocomplete="given-name" required value="{{ old('first_name', $draft['first_name'] ?? '') }}"
                aria-invalid="{{ $errors->has('first_name') ? 'true' : 'false' }}"
                @if ($errors->has('first_name')) aria-describedby="first_name-error" @endif>
            @error('first_name') <p class="form-error" id="first_name-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="form-label" for="last_name">Last name</label>
            <input class="form-input form-input-flow" id="last_name" name="last_name" type="text" autocomplete="family-name" required value="{{ old('last_name', $draft['last_name'] ?? '') }}"
                aria-invalid="{{ $errors->has('last_name') ? 'true' : 'false' }}"
                @if ($errors->has('last_name')) aria-describedby="last_name-error" @endif>
            @error('last_name') <p class="form-error" id="last_name-error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label class="form-label" for="phone">Mobile phone</label>
            <input class="form-input form-input-flow" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required value="{{ old('phone', $draft['phone'] ?? '') }}"
                aria-invalid="{{ $errors->has('phone') ? 'true' : 'false' }}"
                @if ($errors->has('phone')) aria-describedby="phone-error" @endif>
            <p class="form-help">Demo: we won't text this number. We hold it only while this demo runs, and delete it within a few minutes after the demo times out.</p>
            <p class="mt-1">
                <button type="button" class="btn btn-ghost btn-sm" id="demo-example-number" data-example="{{ $examplePhone }}">Use an example number instead</button>
            </p>
            @error('phone') <p class="form-error" id="phone-error">{{ $message }}</p> @enderror
        </div>

        @if (config('referrals.customer_email_field'))
            <div class="field">
                <label class="form-label" for="email">Email (optional)</label>
                <input class="form-input form-input-flow" id="email" name="email" type="email" autocomplete="email" value="{{ old('email', $draft['email'] ?? '') }}"
                    aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                    @if ($errors->has('email')) aria-describedby="email-error" @endif>
                @error('email') <p class="form-error" id="email-error">{{ $message }}</p> @enderror
            </div>
        @endif

        <label class="well mt-5 flex items-start gap-3 text-base">
            <input type="checkbox" id="consent" name="consent" value="1" class="mt-0.5 h-[22px] w-[22px] flex-none accent-action" @checked(old('consent', $draft['consent'] ?? false))
                aria-invalid="{{ $errors->has('consent') ? 'true' : 'false' }}"
                @if ($errors->has('consent')) aria-describedby="consent-error" @endif>
            <span>I agree to receive text messages about my rewards from @include('demo._name', ['business' => $business]). Message and data rates may apply. Reply STOP to opt out.</span>
        </label>
        @error('consent') <p class="form-error mt-2" id="consent-error">{{ $message }}</p> @enderror
    </form>

    <script>
        document.getElementById('demo-example-number').addEventListener('click', function () {
            document.getElementById('phone').value = this.getAttribute('data-example');
        });
    </script>
@endsection

@section('action')
    <button type="submit" form="demo-claim-form" class="btn btn-flow btn-primary w-full">Continue</button>
@endsection
