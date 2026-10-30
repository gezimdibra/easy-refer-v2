{{--
    Notices region (design-system.md Section 6.6): page-level status
    and error summaries live in one region labelled "Notices".

    Error summaries are a SINGLE-SOURCE component (standing rule,
    EMPTY ERROR SUMMARY defect class, 2026-10-29): when the error
    bag is non-empty this partial renders the header count AND the
    list of every message, both derived from the same bag in the
    same block, each message as an anchor to its field. Cross-field
    messages (keyed to no single input, e.g. "account", "card")
    render in the same list. There is no count-only mode and no
    per-page error-display flag: a page cannot opt into a header
    without its messages, because the two cannot diverge here.
    Focus moves to the summary, which is also the aria-live
    announcement (Section 4.2). Messages also render at their
    fields where the form marks them (the summary + field
    duplication is the RT-3 design, 2026-10-27).
--}}
{{-- The status flash is rendered HERE and nowhere else: views in
     layouts that include this partial must not render
     session('status') themselves (the QR and Reviews pages each
     did, and every flash showed twice). One value is not a
     message: 'verification-link-sent' is a machine key the
     verify-email view translates into its own sentence, so it is
     never banner text. --}}
@php($statusMessage = session('status') === 'verification-link-sent' ? null : session('status'))
{{-- RS-14 toast payload (GEN-01/GEN-04): rendered only when it
     belongs to THIS status sentence, so a stale or unrelated
     toast can never attach itself to another page's flash. --}}
@php($toast = session('toast'))
@php($toastForStatus = is_array($toast) && ($toast['message'] ?? null) === $statusMessage && (isset($toast['undo']) || isset($toast['view'])) ? $toast : null)
{{-- The summary reads EVERY error bag, not only the default one:
     forms that validate into a named bag (password change,
     two-factor, QR placement rename) were invisible to a
     default-bag-only summary — their messages rendered at the
     field with no summary at all. Messages are merged by field
     key, identical duplicates collapsed, so the count and the
     list below are two views of this one array. --}}
@php($summaryByKey = [])
@foreach ((method_exists($errors, 'getBags') ? $errors->getBags() : ['default' => $errors]) as $summaryBag)
    @foreach ($summaryBag->keys() as $bagKey)
        @foreach ($summaryBag->get($bagKey) as $bagMessage)
            @php($summaryByKey[$bagKey] = array_values(array_unique(array_merge($summaryByKey[$bagKey] ?? [], [$bagMessage]))))
        @endforeach
    @endforeach
@endforeach
@php($problemCount = count($summaryByKey))
@if ($statusMessage || $problemCount > 0)
    <div role="region" aria-label="Notices">
        @if ($statusMessage)
            @if ($toastForStatus)
                <div class="banner banner-success flex flex-wrap items-center gap-x-3 gap-y-2" role="status">
                    <span>{{ $statusMessage }}</span>
                    @isset($toastForStatus['view'])
                        <a class="font-semibold text-link underline" href="{{ $toastForStatus['view']['url'] }}">{{ $toastForStatus['view']['label'] }}</a>
                    @endisset
                    @isset($toastForStatus['undo'])
                        <form method="POST" action="{{ route('actions.undo') }}" data-loading>
                            @csrf
                            <input type="hidden" name="undo_token" value="{{ $toastForStatus['undo']['token'] }}">
                            <button type="submit" class="btn btn-ghost btn-sm">Undo</button>
                        </form>
                    @endisset
                </div>
            @else
                <p class="banner banner-success" role="status">{{ $statusMessage }}</p>
            @endif
        @endif

        @if ($problemCount > 0)
            <div class="banner banner-error" role="alert" tabindex="-1" data-error-summary>
                <p>{{ $problemCount === 1 ? 'One thing to fix:' : $problemCount.' things to fix:' }}</p>
                <ul class="mt-1 list-inside list-disc">
                    @foreach ($summaryByKey as $summaryKey => $summaryMessages)
                        @foreach ($summaryMessages as $summaryMessage)
                            <li><a class="underline" href="#{{ $summaryKey }}">{{ $summaryMessage }}</a></li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
            @once
                <script>
                    (function () {
                        var el = document.querySelector('[data-error-summary]');
                        if (el) { el.focus(); }
                    })();
                </script>
            @endonce
        @endif
    </div>
@endif
