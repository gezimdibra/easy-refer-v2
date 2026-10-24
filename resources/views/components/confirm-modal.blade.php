@props([
    'action',
    'title',
    'id',
    'confirmLabel' => 'Confirm',
    'confirmClass' => 'btn-danger',
    'cancelLabel' => 'Cancel',
    'method' => 'POST',
    'triggerClass' => 'btn btn-secondary',
])

{{--
    The consequence confirmation modal (design Section 2.5): the
    product's one destructive confirmation pattern. The title
    names the consequence; the body states it in plain sentences
    with the consequence line semibold; Cancel is always present,
    is the safe default, and takes initial focus; the confirming
    action uses the danger variant; focus is trapped while open,
    Escape cancels, focus returns to the invoker on close, and the
    backdrop does not cancel (backdrop clicks cancel only
    non-destructive modals). Max width 480px; a bottom sheet on
    mobile.

    The trigger contract (NEW-2): a trigger slot holding plain
    text renders as a real button carrying the trigger-class
    variant (default btn btn-secondary; pass btn btn-danger or
    btn btn-danger-ghost in a danger zone), so a destructive
    action always shows button affordance at the full .btn target
    size and never appears as bare text. A trigger slot holding
    markup (a full button of the caller's own) renders as given,
    under the same click handling. The confirm form carries
    data-loading, so its submit takes the shared CP-02 loading
    state.
--}}
<div class="inline-block" x-data="{
    open: false,
    invoker: null,
    show() {
        this.invoker = document.activeElement;
        this.open = true;
        // DG-6 (release 08b): the focus call waits one animation
        // frame past the tick. The tick alone raced the x-show
        // display flip in the WebKit capture rig (the trigger
        // still held focus 1.6s after the dialog opened, twice),
        // so initial focus never landed inside the dialog; by
        // the first frame the dialog is displayed and the focus
        // call sticks. Escape, the focus boundary, and the
        // restore on close are unchanged.
        this.$nextTick(() => requestAnimationFrame(() => this.$refs.cancel.focus()));
    },
    cancel() {
        this.open = false;
        if (this.invoker) { this.invoker.focus(); }
    },
    trapTab(event) {
        const focusable = Array.from(this.$refs.dialog.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex=\'-1\'])')).filter(el => ! el.disabled && el.offsetParent !== null);
        if (focusable.length === 0) { return; }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        if (! event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    },
}">
    @php
        $triggerHtml = isset($trigger) ? $trigger->toHtml() : '';
        $triggerIsText = $triggerHtml !== '' && strip_tags($triggerHtml) === $triggerHtml;
    @endphp
    @if ($triggerIsText)
        <button type="button" class="{{ $triggerClass }}" @click="show()">{{ trim($triggerHtml) }}</button>
    @elseif ($triggerHtml !== '')
        <span @click="show()">{{ $trigger }}</span>
    @endif

    <div x-show="open" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center sm:items-center sm:p-4"
        @keydown.escape.window="if (open) { cancel(); }"
        @keydown.tab="if (open) { trapTab($event); }">
        <div class="absolute inset-0 bg-ink-900/60" aria-hidden="true"></div>
        <div class="relative max-h-full w-full max-w-[480px] overflow-y-auto rounded-t-lg border border-line bg-surface-0 shadow-e3 sm:rounded-lg" style="padding: 24px"
            role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title" x-ref="dialog">
            <h2 class="type-title-3 text-text-1" id="{{ $id }}-title">{{ $title }}</h2>
            <div class="type-body mt-2 space-y-2 text-text-2">
                {{ $slot }}
            </div>
            <form method="POST" action="{{ $action }}" class="mt-5 flex justify-end gap-2" data-loading>
                @csrf
                @if ($method !== 'POST')
                    @method($method)
                @endif
                <button type="button" class="btn btn-secondary" x-ref="cancel" @click="cancel()">{{ $cancelLabel }}</button>
                <button type="submit" class="btn {{ $confirmClass }}">{{ $confirmLabel }}</button>
            </form>
        </div>
    </div>
</div>
