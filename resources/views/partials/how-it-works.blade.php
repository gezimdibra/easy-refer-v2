{{--
    How It Works (inventory HOME-HIW-01 to HOME-HIW-04): four steps
    in a row on desktop, stacked on mobile, no carousel. Home passes
    $stepCols to run the steps two-up beside the poster artwork
    (landing-page-spec.md Section 4.3); the verticals keep the row.
    Redesign (redesign-spec.md Section 10.1 item 4): each step gets
    a 40px number medallion, a title-3 heading, and its bullets as
    check-icon rows.
    Copy release (2026-10-24, Amendment 11): step 2 and 4 bodies
    take the slim-down wording; the eight micro-bullets are the
    second layer and, on home, are demoted to the Features panels
    (progressive disclosure, tactics T-05), so home passes
    $showBullets = false. The verticals have no Features section,
    so they keep the bullets here as their only detail layer.
--}}
<ol class="grid gap-8 sm:grid-cols-2 {{ ($stepCols ?? null) === 2 ? 'lg:grid-cols-2' : 'lg:grid-cols-4' }}">
    @foreach ([
        ['Scan the QR code', 'Customers scan your unique QR code at checkout.', ['Instant QR code generation', 'Multiple placement options']],
        ['Share with friends', 'They send their link to friends.', ['Native share sheet and copy link', 'Customizable messages']],
        ['Claim rewards', 'Friends visit your store and receive their welcome reward.', ['Instant validation', 'Flexible reward options']],
        ['Track and engage', 'See every referral. Send bulk texts.', ['Every visit, counted and listed', 'Bulk SMS campaigns']],
    ] as $i => [$title, $text, $bullets])
        <li>
            {{-- D-2 (release 08b) put the numeral ink on the
                 surface-2 tint chip; the owner's release 08e
                 phone check found the chip itself washed out:
                 surface-2 on the surface-1 section ground
                 measures 1.09:1, so the medallion all but
                 disappears. 08e inverts the medallion inside the
                 Ink and Amber system: ink fill (brand-600) with
                 a cream numeral (surface-1), the same pairing
                 as the primary button. Measured after: numeral
                 vs fill about 16:1, fill vs ground about 17:1.
                 Named check STEP-CONTRAST
                 (hidden_files/redesign/08e/measure.mjs) holds
                 the floors: numeral vs fill at least 7:1, fill
                 vs ground at least 3:1. --}}
            <p class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-600 text-surface-1 dark:bg-action dark:text-on-action" aria-hidden="true"><span class="type-title-3">{{ $i + 1 }}</span></p>
            <h3 class="type-title-3 mt-4 text-text-1">{{ $title }}</h3>
            {{-- PROSE-GAP floor (copy release): H3 to body is
                 never under 12px; mt-3, was mt-2. --}}
            <p class="type-body mt-3 text-text-2">{{ $text }}</p>
            @if ($showBullets ?? true)
                <ul class="mt-3 space-y-2">
                    @foreach ($bullets as $bullet)
                        <li class="type-body-sm flex items-start gap-2 text-text-2"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-success-strong" />{{ $bullet }}</li>
                    @endforeach
                </ul>
            @endif
        </li>
    @endforeach
</ol>
