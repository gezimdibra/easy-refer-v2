@props([
    'action',
    'title',
    'id',
    'confirmLabel' => 'Confirm',
    'confirmClass' => 'btn-danger',
    'cancelLabel' => 'Cancel',
    'method' => 'POST',
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
--}}
<div class="inline-block" x-data="{
    open: false,
    invoker: null,
    show() {
        this.invoker = document.activeElement;
        this.open = true;
        this.$nextTick(() => this.$refs.cancel.focus());
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
    <span @click="show()">{{ $trigger }}</span>

    <div x-show="open" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center sm:items-center sm:p-4"
        @keydown.escape.window="if (open) { cancel(); }"
        @keydown.tab="if (open) { trapTab($event); }">
        <div class="absolute inset-0 bg-ink-900/60" aria-hidden="true"></div>
        <div class="relative max-h-full w-full max-w-[480px] overflow-y-auto rounded-t-lg bg-surface-0 p-5 shadow-e3 sm:rounded-lg"
            role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title" x-ref="dialog">
            <h2 class="text-xl font-semibold text-text-1" id="{{ $id }}-title">{{ $title }}</h2>
            <div class="mt-2 space-y-2 text-base text-text-2">
                {{ $slot }}
            </div>
            <form method="POST" action="{{ $action }}" class="mt-5 flex justify-end gap-2">
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
