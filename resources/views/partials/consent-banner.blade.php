{{--
    Consent banner (spec Section 16, copy CONSENT-01). Marketing
    pages only (AP-16): it is the one overlay allowed there, it is
    absent from the customer flow and the dashboard entirely, and a
    spacer keeps it from covering page content or the primary CTA.
    Shown only while the visitor has made no choice.
--}}
@if (! request()->cookies->has('er_consent'))
    <div class="h-40 md:h-24" aria-hidden="true"></div>
    <div class="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-surface-0 shadow-e2" role="region" aria-label="Cookie consent">
        <div class="mx-auto flex max-w-[1120px] flex-col gap-3 px-5 py-4 md:flex-row md:items-center">
            <p class="flex-1 text-sm text-text-2">
                We use cookies to measure how visitors find us. Accept lets us count ad results. Decline keeps ad measurement off; a basic cookie that remembers how you found us is explained in our
                <a class="font-semibold text-brand-700 underline" href="{{ route('legal') }}#privacy-policy">Privacy Policy</a>.
            </p>
            <form method="POST" action="{{ route('consent') }}" class="flex gap-3">
                @csrf
                <button type="submit" name="choice" value="declined" class="btn btn-secondary">Decline</button>
                <button type="submit" name="choice" value="accepted" class="btn btn-primary">Accept</button>
            </form>
        </div>
    </div>
@endif
