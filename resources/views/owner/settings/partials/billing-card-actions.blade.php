{{--
    Replace and remove for the card on file (billing UI spec
    Section 2 Block C, build unit BU-4). Both are the Section
    8.7 step-up action class (spec 9.9): their routes carry the
    step-up middleware, so posting without a fresh verification
    sends the owner through the challenge first. Replace opens
    the SDK capture form in place (its own mount); remove asks
    first with the BILL-08 confirmation, and the removal itself
    is a plain server-bound post, so it carries the app's
    data-loading busy state like every other settings form.
--}}
<div class="mt-4" x-data="{ replacing: false, confirmingRemove: false }">
    <div class="flex flex-wrap gap-3">
        <button type="button" class="btn btn-secondary min-h-12" @click="replacing = !replacing">Replace card</button>
        <button type="button" class="btn btn-secondary min-h-12" @click="confirmingRemove = !confirmingRemove">Remove card</button>
    </div>

    <div x-show="replacing" x-cloak>
        <div id="card-form-replace" class="mt-4"></div>
        @include('owner.settings.partials.billing-card-form', [
            'action' => route('settings.billing.card.replace'),
            'mountId' => 'card-form-replace',
            'submitLabel' => 'Replace card',
        ])
    </div>

    <div x-show="confirmingRemove" x-cloak class="mt-4 rounded-md border border-line bg-surface-2 p-3">
        <p class="text-text-1">Remove this card? Visits keep counting and rewards keep working. Charges pause until you add a card, and the amount you owe stays on your account.</p>
        <form method="POST" action="{{ route('settings.billing.card.remove') }}" data-loading class="mt-3 flex flex-wrap gap-3">
            @csrf
            <button type="submit" class="btn btn-danger min-h-12">Remove card</button>
            <button type="button" class="btn btn-secondary min-h-12" @click="confirmingRemove = false">Keep card</button>
        </form>
    </div>
</div>
