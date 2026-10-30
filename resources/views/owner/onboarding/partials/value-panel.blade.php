{{--
    OB-VAL-01 (Amendment 44, RS2-3; wording Amendment 57,
    owner ruling 2026-10-29): the optional typical-spend
    value panel. ONE partial included by the finish screen's
    every branch, rendered only while the business's average
    ticket is unset, so the branches cannot drift. Its own
    small form — never folded into the onboarding.complete
    door forms — so Skip is doing nothing and pressing a door.
    The figure it writes (businesses.average_ticket_cents) is
    an internal estimate input: it is read by no charge,
    reward, cap, or ranking path, and is never published.
--}}
<div class="panel mt-8">
    <h2 class="type-title-3 text-text-1">How much does a typical customer spend in one visit?</h2>
    <p class="type-body mt-2 text-text-2">About what a customer spends on one visit at your store. Your Overview uses it to estimate the sales your visits brought in.</p>

    <form method="POST" action="{{ route('onboarding.value') }}" class="mt-4" data-loading>
        @csrf
        <label for="average_ticket">Typical spend per visit ($)</label>
        <input id="average_ticket" name="average_ticket" type="text" inputmode="decimal"
            value="{{ old('average_ticket') }}"
            aria-invalid="{{ $errors->has('average_ticket') ? 'true' : 'false' }}"
            @if ($errors->has('average_ticket')) aria-describedby="average_ticket-error average_ticket-help" @else aria-describedby="average_ticket-help" @endif>
        @error('average_ticket') <p class="form-error" id="average_ticket-error">{{ $message }}</p> @enderror
        <p class="form-help" id="average_ticket-help">Your best guess is fine. You can change it any time in Settings, Value, and changing it never changes what you are charged.</p>
        <p class="mt-4"><button type="submit" class="btn btn-secondary">Save</button></p>
    </form>
</div>
