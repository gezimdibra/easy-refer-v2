{{--
    The Section 3.5 success flood, shared by the staff result
    screen (R8b) and the customer receipts (R7 redeemed, R9
    claimed). Redesign (redesign-spec.md Section 7.8): the flood
    becomes a 72px success-fill medallion with the check glyph at
    36px in success-strong (the 150ms scale-in stays), the reward
    as the title-2 headline, and the business name and the
    confirmation timestamp as a well beneath. Below, one line:
    $note ("Show this screen if anyone asks." at the counter;
    REF-CLAIM-03's "Keep this screen as your receipt." on the
    customer's own receipt).

    R4-U4 (Surface 3 pick A, "Flood+"; copy Amendment 29
    [R4U4-1]): the flood itself is unchanged; under the headline
    the outcome word ($outcome, "Reward redeemed" or "Reward
    claimed", the two wordings never interchangeable) reads in
    success-strong, and the facts move into labelled rows
    (Business / Confirmed) in the well. The timestamp renders
    in business-local time through Business::localTime (Train
    3.5): "Today, {time}" when the confirmation falls on the
    business-local day itself, otherwise the business-local
    date and time in the format this receipt has always used.
    Callers pass the $business model so both sides of the
    counter read the same receipt.
--}}
@php($confirmed = $confirmedAt instanceof \Illuminate\Support\Carbon ? $confirmedAt : \Illuminate\Support\Carbon::parse($confirmedAt instanceof \DateTimeInterface ? $confirmedAt->format(DATE_ATOM) : (string) $confirmedAt))
@php($businessModel = ($business ?? null) instanceof \App\Models\Business ? $business : null)
@php($local = $businessModel !== null ? $businessModel->localTime($confirmed) : $confirmed->copy()->setTimezone((string) config('app.timezone')))
@php($localNow = $businessModel !== null ? $businessModel->localTime(now()) : now()->setTimezone((string) config('app.timezone')))
@php($confirmedDate = $local->toDateString() === $localNow->toDateString() ? 'Today' : $local->format('F j, Y'))
<div class="text-center">
    <div class="flood-check mx-auto flex h-[72px] w-[72px] items-center justify-center rounded-full bg-success-fill" aria-hidden="true">
        <svg class="h-9 w-9 text-success-strong" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
    </div>
    <p class="type-title-2 mt-5 text-text-1">{{ $rewardText }}</p>
    @if (! empty($outcome ?? null))
        <p class="type-body mt-2 font-semibold text-success-strong">{{ $outcome }}</p>
    @endif
    <div class="well mt-5">
        <p class="flex items-baseline justify-between gap-3">
            <span class="type-caption text-text-2">Business</span>
            <span class="font-medium text-text-1">{{ $businessName }}</span>
        </p>
        <p class="mt-2 flex items-baseline justify-between gap-3">
            <span class="type-caption text-text-2">Confirmed</span>
            <span class="font-medium text-text-1">{{ $confirmedDate }}, <span class="tabular-nums">{{ $local->format('g:i A') }}</span></span>
        </p>
    </div>
    <p class="type-body-sm mt-4 text-text-2">{{ $note }}</p>
</div>
