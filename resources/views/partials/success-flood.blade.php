{{--
    The Section 3.5 success flood, shared by the staff result
    screen (R8b) and the customer receipts (R7 redeemed, R9
    claimed). Redesign (redesign-spec.md Section 7.8): the flood
    becomes a 72px success-fill medallion with the check glyph at
    36px in success-strong (the 150ms scale-in stays), the reward
    as the title-2 headline, and the business name and the
    confirmation timestamp (server time governs, EC-50) as a well
    beneath. Below, one line: $note ("Show this screen if anyone
    asks." at the counter; REF-CLAIM-03's "Keep this screen as
    your receipt." on the customer's own receipt).
--}}
@php($confirmed = $confirmedAt instanceof \DateTimeInterface ? $confirmedAt : \Carbon\Carbon::parse($confirmedAt))
<div class="text-center">
    <div class="flood-check mx-auto flex h-[72px] w-[72px] items-center justify-center rounded-full bg-success-fill" aria-hidden="true">
        <svg class="h-9 w-9 text-success-strong" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
    </div>
    <p class="type-title-2 mt-5 text-text-1">{{ $rewardText }}</p>
    <div class="well mt-5">
        <p class="type-body font-medium text-text-1">{{ $businessName }}</p>
        <p class="type-caption mt-1 text-text-2">Confirmed {{ $confirmed->format('F j, Y') }}, <span class="tabular-nums">{{ $confirmed->format('g:i A') }}</span></p>
    </div>
    <p class="type-body-sm mt-4 text-text-2">{{ $note }}</p>
</div>
