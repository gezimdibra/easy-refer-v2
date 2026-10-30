{{--
    CF-1 (post-r26 lane B2, design pack 02-customer-forms Variant
    A): ONE shared customer form renders Add and Edit. Same group
    headings ("Name" / "Phone and texts"), same field order
    (First / Last side by side at sm+, then the phone position),
    same footer. The modes differ only where the design says:
    Add takes a phone with the consent switch row under it; Edit
    shows the phone as a fact row in the same position and opens
    with the customer's identity header (CF-4). Cancel follows
    the stated rule: Add returns to the list, Edit to the detail
    page (both targets unchanged from the shipped pages).
--}}
@php($isEdit = ($mode ?? 'create') === 'edit')
<form method="POST" action="{{ $isEdit ? route('customers.update', $membership) : route('customers.store') }}" class="panel mt-5 [&_input]:max-w-[560px]" data-loading>
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif


    @if ($isEdit)
        {{-- CF-4: identity header in the R1 detail grammar:
             monogram, name as the panel heading, status pill,
             meta line (joined date + texts state). --}}
        <div class="flex items-start gap-3">
            <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-surface-2 text-text-1" aria-hidden="true"><span style="font-size: 13px; font-weight: 650">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($membership->customer->first_name, 0, 1)) }}{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($membership->customer->last_name, 0, 1)) }}</span></span>
            <div class="min-w-0 flex-1">
                <h2 class="type-title-3 text-text-1">{{ $membership->customer->fullName() }}</h2>
                <p class="text-sm text-text-2">Joined {{ $business->localTime($membership->created_at)->format('M j, Y') }} · {{ $membership->sms_consent ? 'OK to text' : 'No texts' }}</p>
                @if ($membership->consent_source === 'owner_asserted')
                    <p class="text-sm text-warning-text" style="font-weight: 550">Added by you, waiting for their confirmation</p>
                @endif
            </div>
            @if ($membership->sms_consent)
                <span class="pill pill-success flex-none">OK to text</span>
            @else
                <span class="pill pill-neutral flex-none">No texts</span>
            @endif
        </div>
    @endif

    <h2 class="type-title-3 text-text-1 {{ $isEdit ? 'mt-6' : 'mt-1' }}">Name</h2>
    <div class="mt-3 grid gap-4 sm:grid-cols-2">
        <div>
            <label for="first_name">First name</label>
            <input id="first_name" name="first_name" type="text" value="{{ old('first_name', $isEdit ? $membership->customer->first_name : '') }}" required autocomplete="given-name"
                aria-invalid="{{ $errors->has('first_name') ? 'true' : 'false' }}"
                @if ($errors->has('first_name')) aria-describedby="first_name-error" @endif>
            @error('first_name') <p class="form-error" id="first_name-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="last_name">Last name</label>
            <input id="last_name" name="last_name" type="text" value="{{ old('last_name', $isEdit ? $membership->customer->last_name : '') }}" autocomplete="family-name"
                aria-invalid="{{ $errors->has('last_name') ? 'true' : 'false' }}"
                @if ($errors->has('last_name')) aria-describedby="last_name-error" @endif>
            @error('last_name') <p class="form-error" id="last_name-error">{{ $message }}</p> @enderror
        </div>
    </div>

    <h2 class="type-title-3 mt-6 text-text-1">Phone and texts</h2>
    @if (! $isEdit)
        <div class="mt-3">
            <label for="phone">Phone number</label>
            <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" required autocomplete="tel"
                aria-invalid="{{ $errors->has('phone') ? 'true' : 'false' }}"
                @if ($errors->has('phone')) aria-describedby="phone-error phone-help" @else aria-describedby="phone-help" @endif>
            @error('phone')
                <p class="form-error" id="phone-error">{{ $message }}@if (session('duplicate_membership')) <a class="font-semibold text-link underline" href="{{ route('customers.show', session('duplicate_membership')) }}">Open their page</a>@endif</p>
            @enderror
            <p class="form-help" id="phone-help">US number. We text the confirmation to this number.</p>
        </div>

        {{-- CF-3: consent is the switch-row pattern (bold label,
             [CF-2] consequence line under it); a consent error
             renders at this row, not only as a page notice. --}}
        <div class="mt-5">
            <label class="flex items-start gap-3">
                <input type="checkbox" id="consent" name="consent" value="1" @checked(old('consent')) class="switch mt-1"
                    aria-invalid="{{ $errors->has('consent') ? 'true' : 'false' }}"
                    @if ($errors->has('consent')) aria-describedby="consent-error" @endif>
                <span>
                    <span class="font-semibold">Agreed to receive text messages</span>
                    <span class="block text-text-2">We send one text so they can confirm. No confirmation, no referral link.</span>
                </span>
            </label>
            @error('consent') <p class="form-error" id="consent-error">{{ $message }}</p> @enderror
        </div>
    @else
        {{-- CF-4: the phone is the person's identity key, not an
             attribute (small-gaps design 1.4): it renders as a
             compact fact row, never an input, with the two real
             remedies in a disclosure. Remedy lines are the
             shipped paragraph's sentences verbatim, one per
             line (Amendment 29 [CF-3], draft for 29.1). --}}
        <div class="mt-3">
            <label>Phone number</label>
            <p class="text-text-1">{{ \App\Support\PhoneNumber::formatForDisplay($membership->customer->phone_e164) }}</p>
            <details class="mt-2">
                <summary class="cursor-pointer text-sm font-semibold text-link">Why can't I change this?</summary>
                <div class="mt-2 space-y-2 text-sm text-text-2">
                    <p>Phone numbers can't be changed here, because texts and sign-in codes go to this number.</p>
                    <p>If your customer got a new number, ask them to scan your QR code and enter the new number; we connect it to their account automatically.</p>
                    <p>If you typed the number wrong, remove them from your store and add them again with the right number.</p>
                </div>
            </details>
        </div>
    @endif

    {{-- CF-5: on mobile the footer sticks to the bottom of the
         viewport so Save / Add stays reachable at the
         keyboard-open scroll position; at sm+ it is the shipped
         inline footer. --}}
    <div class="sticky bottom-0 z-10 mt-6 -mx-6 -mb-6 flex items-center justify-between border-t border-line bg-surface-0 px-6 py-4 sm:static sm:mx-0 sm:mb-0 sm:bg-transparent sm:px-0 sm:pb-0 sm:pt-5">
        <a class="btn btn-ghost" href="{{ $isEdit ? route('customers.show', $membership) : route('customers.index') }}">Cancel</a>
        <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Save changes' : 'Add a customer' }}</button>
    </div>
</form>
