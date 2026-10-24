{{--
    Consent banner (spec Section 16, copy CONSENT-01). Marketing
    pages only (AP-16): it is the one overlay allowed there, it is
    absent from the customer flow and the dashboard entirely.
    Shown only while the visitor has made no choice. The redesign
    renders it as a floating card (redesign-spec.md Section 10.1
    item 12).

    Release 08b (D-7/R21, F2): the wording is R21 verbatim, set at
    body-sm (D-7: consent is a notice, not a lede). Below lg the
    card takes its compact form (F2): a slim bottom bar carrying
    the one-line summary and both buttons inline, so the first
    viewport keeps the hero visual visible; the full R21 text
    renders at lg and up, and the Privacy Policy link in both
    forms carries the full disclosure. Both buttons stay btn-sm
    in both forms (DP-02: dismissal paths at equal weight). The
    A8-02 cap (M-03) is unchanged.
--}}
@if (! request()->cookies->has('er_consent'))
    <div class="consent-card fixed inset-x-0 bottom-0 z-40 border-t border-line bg-surface-0 shadow-e2 max-lg:rounded-none lg:inset-x-auto lg:right-6 lg:bottom-6 lg:w-[380px] lg:rounded-lg lg:border" role="region" aria-label="Cookie consent">
        <div class="consent-inner flex flex-col gap-4 p-5">
            <p class="type-caption flex-1 text-text-2 lg:hidden">
                Cookies: we measure how visitors find us and whether our ads work. Accept turns that on; Decline keeps it off. One basic cookie remembers how you found us either way; our
                <a class="font-semibold text-brand-700 underline" href="{{ route('legal') }}#privacy-policy">Privacy Policy</a> explains it.
            </p>
            <p class="type-body-sm hidden flex-1 text-text-2 lg:block">
                We use cookies to see how visitors find us and whether our ads work. Accept turns that measurement on. Decline keeps it off. One basic cookie remembers how you found us either way; our
                <a class="font-semibold text-brand-700 underline" href="{{ route('legal') }}#privacy-policy">Privacy Policy</a> explains it.
            </p>
            <form method="POST" action="{{ route('consent') }}" class="flex justify-end gap-3">
                @csrf
                <button type="submit" name="choice" value="declined" class="btn btn-secondary btn-sm">Decline</button>
                <button type="submit" name="choice" value="accepted" class="btn btn-primary btn-sm">Accept</button>
            </form>
        </div>
    </div>
@endif
