{{--
    The Square Web Payments SDK capture form (billing UI spec
    Section 3, build unit BU-4). Square renders the card fields
    into the mount element ($mountId, rendered by the caller)
    inside its own iframes: the card number never touches our
    DOM, our server, our logs, or our database. On submit the
    SDK tokenizes and only the single-use source id is posted
    to $action.

    The SDK script loads deferred and the page never waits on
    it; if it cannot load, BILL-02 shows and the rest of the
    page keeps working. Tokenize errors land in the BILL-13
    summary (focused) with Square's per-field messages listed,
    and outcomes are announced in the polite live region.

    This form deliberately does NOT carry the app's data-loading
    attribute: that mechanism locks the submit button until the
    answering page loads, which is right for a server-bound post
    but would strand the button after a declined tokenize, when
    the owner must be able to try again. The form manages the
    same busy state itself (disabled, aria-busy, the shared
    spinner) only while tokenizing, and releases it on error.

    SDK styles are fed from the page's computed token values at
    init, so the hosted fields follow the light/dark token
    ladder exactly (spec Section 6); no raw hex appears here.

    STYLE VALIDATION (learned live 2026-10-25 against the
    CDN-served SDK 1.87.1; sqfix probes 1-4): the SDK validates
    the style object when attach runs and rejects the entire
    form on any invalid entry with InvalidStylesError. The
    input element takes the ELEMENT selectors 'input' and
    'input::placeholder'; the class forms '.input' and
    '.input::placeholder' are invalid. A font family value is
    likewise rejected for any named family (the computed stack,
    a bare family name, and a name-plus-generic list all fail;
    only a bare generic family passes), and the hosted iframe
    cannot load this page's webfont in any case, so no font
    family is passed at all. The original class selectors plus
    the computed font stack are why the form never rendered in
    any browser: attach rejected, the catch showed BILL-02, and
    the error went nowhere. Failures now reach the console via
    console.error in the failure paths below, so the next SDK
    change is diagnosable instead of silent.
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
        <p class="font-semibold text-text-1">Some card details need another look.</p>
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

                form.addEventListener('submit', function (event) {
                    event.preventDefault();

                    if (form.dataset.tokenizing === 'true') {
                        return;
                    }

                    form.dataset.tokenizing = 'true';
                    summary.hidden = true;
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
