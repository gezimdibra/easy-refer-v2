{{--
    The receipt card's composition (85x55mm), shared by the
    single card sheet and the multi-up sheet of cards (RC-3
    Candidate F, Amendment 41): one composition, never forked,
    so the two print forms cannot drift.

    RC-3 Candidate A surgery (Amendment 41): the card names the
    business (a card found in a wallet a week later must answer
    "whose reward is this?") and tells the buyer the one
    productive path in words addressed to them: scan it
    yourself, then send it to a friend. The space is funded by
    dropping the trust line from the CARD only (it stays on the
    strip and every other sheet) and by a tighter rhythm
    (2px stack margins, 18/19px line heights on the two display
    lines): with a long business name and a long join slug the
    fallback line wraps to two lines and must still sit inside
    the 55mm box — proven in the lane's real-size captures
    (Harbor Street Barbers), not only with the short fixture
    name. Inherits $business, the poster-headline composition
    variables ($rewardText, $headlineSplit, $rewardAmount/
    $rewardRest, $referrerAmount/$referrerRest), $imageUrl
    and $joinDisplay from the poster view.

    Amendment 43 (card-fit hotfix): the Amendment 41 proof
    above did not survive live content — the r45 re-check
    (defect D-1) measured biz 7's card at 227px of content in
    a 206px box, the fallback's last wrapped line clipped,
    because the proof fixture carried a 2-line give-get and a
    short localhost fallback while live carries 3 lines of
    each. The fix, measured on the WebKit rig against the
    worst case (PosterPrintFitTest's named card-fit check,
    which pins all three text states of this card): the
    fallback paragraph leaves the narrow text column and runs
    full-width under the card body, where the whole
    production address fits on one line; the text column's
    rhythm trims (1px stack margins, 12px leadings on the two
    body lines, 15/16 thanks line, 16/18 reward headline,
    14/16 in the no-friend-text headline state); and the card
    boxes' vertical padding goes 3mm -> 2mm (poster view,
    single sheet and cut guides alike). The QR block is
    untouched: the code is the card's primary path and was
    never the overflow. Every Amendment 41 string was
    byte-preserved until Amendment 58 [REF-POSTER-04],
    which rewrites the card's headline, instruction, and
    CTA under the owner's Finding 4 ruling (see below).
--}}
<div>
    <div class="flex items-center gap-3">
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-text-1" style="font-size: 11px; line-height: 14px">{{ $business->name }}</p>
            <p class="font-semibold text-text-1" style="margin-top: 1px; font-size: 15px; line-height: 16px">Thanks for coming in.</p>
            {{-- Amendment 58 [REF-POSTER-04]: the card's
                 headline is the shared give/get headline; the
                 old pass-2 give/get line is retired here too
                 (the headline IS that sentence), which also
                 returns its 3 worst-case lines to the card's
                 fit budget. The buyer instruction drops "join"
                 and echoes the headline's share verb. --}}
            <h1 class="text-text-1" style="margin-top: 1px; font-size: 16px; line-height: 18px; font-weight: 650; letter-spacing: -0.01em">@include('owner.partials.poster-headline')</h1>
            <p class="text-text-1" style="margin-top: 1px; font-size: 10.5px; line-height: 12px">Scan this card, then share it with a friend.</p>
        </div>
        <div class="flex-none text-center">
            <div class="poster-halo inline-block" style="padding: 4px; border-radius: 14px; background-color: #F5A300">
                <div class="rounded-lg" style="background-color: #ffffff; border: 1px solid #E7E0D2; padding: 6px">
                    <img src="{{ $imageUrl }}" alt="QR code for {{ $business->name }}" class="block" style="width: 104px; height: 104px">
                </div>
            </div>
            <p class="mt-1 font-semibold text-text-1" style="font-size: 9px; line-height: 12px">Scan to share</p>
        </div>
    </div>
    <p class="text-text-2" style="margin-top: 4px; font-size: 9px; line-height: 12px">Can't scan? Go to {{ $joinDisplay }}</p>
</div>
