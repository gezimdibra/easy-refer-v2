{{--
    Consent banner (spec Section 16, copy CONSENT-01). Marketing
    pages only (AP-16): it is the one overlay allowed there, it is
    absent from the customer flow and the dashboard entirely, and a
    spacer keeps it from covering page content or the primary CTA.
    Shown only while the visitor has made no choice. The redesign
    renders it as a floating card (redesign-spec.md Section 10.1
    item 12); no spacer is needed because the card overlays no
    page content permanently.
--}}
@if (! request()->cookies->has('er_consent'))
    {{-- M-03 (benchmark fix lane, 2026-10-24): the card is capped
         at the smaller of its 380px design width and the viewport
         minus the 16px side insets, so the card and both of its
         buttons always sit inside the frame, on a 360px phone and
         at every other width. The rule lives in app.css
         (graduated at the release 07 integration). --}}
    <div class="consent-card fixed inset-x-4 bottom-4 z-40 rounded-lg border border-line bg-surface-0 shadow-e2 sm:inset-x-auto sm:right-6 sm:bottom-6 sm:w-[380px]" role="region" aria-label="Cookie consent">
        <div class="flex flex-col gap-4 p-5">
            <p class="type-body-sm flex-1 text-text-2">
                We use cookies to measure how visitors find us. Accept lets us count ad results. Decline keeps ad measurement off; a basic cookie that remembers how you found us is explained in our
                <a class="font-semibold text-brand-700 underline" href="{{ route('legal') }}#privacy-policy">Privacy Policy</a>.
            </p>
            <form method="POST" action="{{ route('consent') }}" class="flex justify-end gap-3">
                @csrf
                <button type="submit" name="choice" value="declined" class="btn btn-secondary btn-sm">Decline</button>
                <button type="submit" name="choice" value="accepted" class="btn btn-primary btn-sm">Accept</button>
            </form>
        </div>
    </div>
@endif
