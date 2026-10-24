{{--
    How It Works (inventory HOME-HIW-01 to HOME-HIW-04): four steps
    in a row on desktop, stacked on mobile, no carousel. Home passes
    $stepCols to run the steps two-up beside the poster artwork
    (landing-page-spec.md Section 4.3); the verticals keep the row.
    Redesign (redesign-spec.md Section 10.1 item 4): each step gets
    a 40px number medallion, a title-3 heading, and its bullets as
    check-icon rows.
--}}
<ol class="grid gap-8 sm:grid-cols-2 {{ ($stepCols ?? null) === 2 ? 'lg:grid-cols-2' : 'lg:grid-cols-4' }}">
    @foreach ([
        ['Scan the QR code', 'Customers scan your unique QR code at checkout.', ['Instant QR code generation', 'Multiple placement options']],
        ['Share with friends', 'They share the referral link with friends through their preferred platform.', ['Native share sheet and copy link', 'Customizable messages']],
        ['Claim rewards', 'Friends visit your store and receive their welcome reward.', ['Instant validation', 'Flexible reward options']],
        ['Track and engage', 'Access your dashboard to track referrals and send bulk SMS marketing.', ['Real-time analytics', 'Bulk SMS campaigns']],
    ] as $i => [$title, $text, $bullets])
        <li>
            {{-- D-2 (release 08b): the step numeral is ink on the
                 tint chip, at title-3 size, so the sequence reads
                 at a glance instead of washing out. --}}
            <p class="flex h-10 w-10 items-center justify-center rounded-full bg-surface-2 text-text-1" aria-hidden="true"><span class="type-title-3">{{ $i + 1 }}</span></p>
            <h3 class="type-title-3 mt-4 text-text-1">{{ $title }}</h3>
            <p class="type-body mt-2 text-text-2">{{ $text }}</p>
            <ul class="mt-3 space-y-2">
                @foreach ($bullets as $bullet)
                    <li class="type-body-sm flex items-start gap-2 text-text-2"><x-icon name="check" :size="16" class="mt-0.5 flex-none text-success-strong" />{{ $bullet }}</li>
                @endforeach
            </ul>
        </li>
    @endforeach
</ol>
