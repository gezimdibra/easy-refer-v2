{{--
    The Section 3.5 success flood, shared by the staff result
    screen (R8b) and the customer receipts (R7 redeemed, R9
    claimed): a full-width success-strong block with the check
    glyph in a white circle, the reward as the white headline, the
    business name, and the confirmation timestamp with its time
    (server time governs, EC-50). Below the flood, on white, one
    line: $note ("Show this screen if anyone asks." at the
    counter; REF-CLAIM-03's "Keep this screen as your receipt."
    on the customer's own receipt).
--}}
@php($confirmed = $confirmedAt instanceof \DateTimeInterface ? $confirmedAt : \Carbon\Carbon::parse($confirmedAt))
<div class="overflow-hidden rounded-lg shadow-e1">
    <div class="bg-success-strong px-5 py-8 text-center text-white">
        <div class="flood-check mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-white" aria-hidden="true">
            <svg class="h-9 w-9 text-success-strong" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        </div>
        <p class="mt-4 text-[28px] font-bold leading-tight">{{ $rewardText }}</p>
        <p class="mt-1 text-lg">{{ $businessName }}</p>
        <p class="mt-2 text-base">Confirmed {{ $confirmed->format('F j, Y') }}, {{ $confirmed->format('g:i A') }}</p>
    </div>
    <div class="bg-surface-0 px-5 py-4 text-center">
        <p class="text-text-2">{{ $note }}</p>
    </div>
</div>
