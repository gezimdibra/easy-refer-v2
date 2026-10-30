{{--
    The Square card capture form, shared by its two hosts
    (extracted in OB-U4): Settings, Billing (capture and
    replace) and the onboarding Card step. One form, so the
    FE-CARD-01 pristine guard below ships to both hosts from
    this single file. Parameters: $action (the host's save
    route), $mountId (the element the Web Payments SDK mounts
    into), $submitLabel, and optionally $formId,
    $replacing (a quieter submit label ending), $card (the
    current default, for the replace framing).
--}}
@php
    $squareEnvironment = (string) config('services.square.environment', 'sandbox');
    $squareSdkUrl = $squareEnvironment === 'production'
        ? 'https://web.squarecdn.com/v1/square.js'
        : 'https://sandbox.web.squarecdn.com/v1/square.js';
@endphp
<div data-billing-card-ui>
    <div data-billing-sdk-error hidden class="mt-4 rounded-md border border-line bg-surface-2 p-3">
        <p class="text-text-1">The secure card form could not load. Check your connection and try again. Your other billing details are up to date.</p>
    </div>

    <div data-billing-tokenize-errors hidden tabindex="-1" class="mt-4 rounded-md border border-line bg-surface-2 p-3">
        <p class="font-semibold text-text-1" data-billing-tokenize-heading>Some card details need another look.</p>
        <p class="text-text-1" data-billing-card-hint hidden>Enter your card details first.</p>
        <ul data-billing-tokenize-error-list class="mt-1 list-disc pl-5 text-text-1"></ul>
    </div>

    <form method="POST" action="{{ $action }}" data-billing-card-form-element class="mt-4">
        @csrf
        <input type="hidden" name="source_id" value="">
        <button type="submit" class="btn btn-primary min-h-12" data-billing-card-submit disabled>{{ $submitLabel }}</button>
    </form>

    <div class="sr-only" aria-live="polite" data-billing-live></div>
</div>

<script src="{{ $squareSdkUrl }}" defer></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var mountId = @json($mountId);
        var ui = document.querySelector('[data-billing-card-ui]');
        var mount = document.getElementById(mountId);

        if (!ui || !mount) {
            return;
        }

        var form = ui.querySelector('[data-billing-card-form-element]');
        var submit = ui.querySelector('[data-billing-card-submit]');
        var sourceField = form.querySelector('input[name="source_id"]');
        var sdkError = ui.querySelector('[data-billing-sdk-error]');
        var summary = ui.querySelector('[data-billing-tokenize-errors]');
        var heading = ui.querySelector('[data-billing-tokenize-heading]');
        var hint = ui.querySelector('[data-billing-card-hint]');
        var errorList = ui.querySelector('[data-billing-tokenize-error-list]');
        var live = ui.querySelector('[data-billing-live]');

        function loadFailed(error) {
            if (error) {
                console.error('[billing] The secure card form failed to load.', error);
            }
            sdkError.hidden = false;
            live.textContent = sdkError.textContent.trim();
        }

        function setBusy(busy) {
            submit.disabled = busy;
            submit.setAttribute('aria-busy', busy ? 'true' : 'false');
            submit.classList.toggle('is-loading', busy);

            var spinner = submit.querySelector('.spinner');
            if (busy && !spinner) {
                spinner = document.createElement('span');
                spinner.className = 'spinner';
                spinner.setAttribute('aria-hidden', 'true');
                submit.prepend(spinner);
            }
            if (!busy && spinner) {
                spinner.remove();
            }
        }

        if (!window.Square) {
            loadFailed();
            return;
        }

        var computed = getComputedStyle(document.documentElement);

        function token(name) {
            return computed.getPropertyValue(name).trim();
        }

        var payments;

        try {
            payments = window.Square.payments(
                @json((string) config('services.square.application_id')),
                @json((string) config('services.square.location_id'))
            );
        } catch (error) {
            loadFailed(error);
            return;
        }

        payments.card({
            style: {
                '.input-container': {
                    borderColor: token('--color-line'),
                    borderRadius: '8px'
                },
                'input': {
                    color: token('--color-text-1'),
                    fontSize: '16px'
                },
                'input::placeholder': {
                    color: token('--color-text-2')
                },
                '.message-text': {
                    color: token('--color-error-text')
                }
            }
        }).then(function (card) {
            return card.attach('#' + mountId).then(function () {
                submit.disabled = false;

                // FE-CARD-01: track each field's content state
                // from the card component's own input events.
                // Every event carries the field it came from
                // and that field's currentState.isEmpty, so the
                // page knows whether anything has actually been
                // typed without ever seeing a digit (the values
                // never leave Square's iframes). The guard is
                // keyed on this content state, not on focus: a
                // field that was only tabbed through is still
                // empty, and its submit is still pristine.
                var fieldsWithContent = {};

                function cardHasContent() {
                    return Object.keys(fieldsWithContent).some(function (field) {
                        return fieldsWithContent[field];
                    });
                }

                function clearCardHint() {
                    if (hint.hidden) {
                        return;
                    }

                    hint.hidden = true;
                    heading.hidden = false;
                    summary.hidden = true;
                }

                function trackFieldContent(cardInputEvent) {
                    var detail = (cardInputEvent && cardInputEvent.detail) || cardInputEvent || {};

                    if (!detail.field || !detail.currentState) {
                        return;
                    }

                    fieldsWithContent[detail.field] = detail.currentState.isEmpty === false;

                    if (cardHasContent()) {
                        clearCardHint();
                    }
                }

                ['cardBrandChanged', 'errorClassAdded', 'errorClassRemoved', 'focusClassAdded', 'focusClassRemoved', 'postalCodeChanged'].forEach(function (eventType) {
                    card.addEventListener(eventType, trackFieldContent);
                });

                function showCardHint() {
                    errorList.innerHTML = '';
                    heading.hidden = true;
                    hint.hidden = false;
                    summary.hidden = false;
                    live.textContent = 'Enter your card details first.';

                    if (typeof card.focus === 'function') {
                        var focusing = card.focus('cardNumber');
                        if (focusing && typeof focusing.catch === 'function') {
                            focusing.catch(function () {});
                        }
                    }
                }

                form.addEventListener('submit', function (event) {
                    event.preventDefault();

                    if (form.dataset.tokenizing === 'true') {
                        return;
                    }

                    // FE-CARD-01: a submit while every field is
                    // still empty is not a card attempt. Do not
                    // tokenize it (Square would answer with one
                    // error per empty field and paint the hosted
                    // fields invalid); point at the fields with
                    // the hint instead. Once any field has
                    // content, the attempt below is unchanged.
                    if (!cardHasContent()) {
                        showCardHint();
                        return;
                    }

                    form.dataset.tokenizing = 'true';
                    summary.hidden = true;
                    hint.hidden = true;
                    heading.hidden = false;
                    setBusy(true);

                    card.tokenize().then(function (result) {
                        if (result.status === 'OK') {
                            sourceField.value = result.token;
                            form.submit();
                            return;
                        }

                        form.dataset.tokenizing = 'false';
                        setBusy(false);
                        errorList.innerHTML = '';
                        (result.errors || []).forEach(function (error) {
                            var item = document.createElement('li');
                            item.textContent = error.message;
                            errorList.appendChild(item);
                        });
                        summary.hidden = false;
                        summary.focus();
                        live.textContent = 'Some card details need another look.';
                    }).catch(function (error) {
                        console.error('[billing] Card tokenize failed.', error);
                        form.dataset.tokenizing = 'false';
                        setBusy(false);
                        errorList.innerHTML = '';
                        summary.hidden = false;
                        summary.focus();
                    });
                });
            });
        }).catch(loadFailed);
    });
</script>
