{{--
    RS-N3 (Train B, Amendment 39): the honest "no catch" strip,
    one source for the friend landing and the enrollment landing.
    It sits directly under the phone field, above the consent
    checkbox, and answers the two questions the phone field
    raises: who pays, and what happens to the number. Three
    one-line rows, each check-led, in one quiet well.

    State-truth (design pass Section 4.1/4.4): Row 1 asserts who
    funds the PROGRAM, never that a fee moved for this visit, so
    it stays true on the free visits, past the cap, in grace, in
    pause, and in closure: the business is the only party the
    platform ever charges, and no code path charges a customer.
    Row 3 states the texts' true scope: the one consent covers
    reward texts AND the business's campaign texts, so the strip
    names the offers and the STOP that covers all of it. The
    rejected backlog wording ("only if you visit", "only about
    this reward") is recorded as rejected in Amendment 39 and
    must never render here. No fee amount appears in the strip.

    $frame is the partial's only parameter: 'friend' on the
    friend claim form (the reward frame), 'join' on the
    enrollment form (the joining frame). Rows 2 and 3 are
    identical in both frames.
--}}
<div class="well mt-4" data-no-catch-strip>
    @if (($frame ?? 'friend') === 'join')
        <p class="flex items-start gap-2 text-text-2"><svg class="mt-1 h-4 w-4 flex-none text-success-strong" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg><span>Joining is free. You pay nothing for this program. {{ $business->name }} pays for it.</span></p>
    @else
        <p class="flex items-start gap-2 text-text-2"><svg class="mt-1 h-4 w-4 flex-none text-success-strong" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg><span>You pay nothing to get or use this reward. {{ $business->name }} pays for the program.</span></p>
    @endif
    <p class="mt-2 flex items-start gap-2 text-text-2"><svg class="mt-1 h-4 w-4 flex-none text-success-strong" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg><span>Your number is never sold.</span></p>
    <p class="mt-2 flex items-start gap-2 text-text-2"><svg class="mt-1 h-4 w-4 flex-none text-success-strong" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg><span>We text you about your rewards. {{ $business->name }} may also text you offers. Reply STOP to stop the texts.</span></p>
</div>
