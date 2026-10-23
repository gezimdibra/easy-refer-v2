{{--
    How It Works (inventory HOME-HIW-01 to HOME-HIW-04): four steps
    in a row on desktop, stacked on mobile, no carousel.
--}}
<ol class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
    @foreach ([
        ['Scan the QR Code', 'Customers scan your unique QR code at checkout.', ['Instant QR code generation', 'Multiple placement options']],
        ['Share With Friends', 'They share the referral link with friends through their preferred platform.', ['Social media integration', 'Customizable messages']],
        ['Claim Rewards', 'Friends visit your store and receive their welcome reward.', ['Instant validation', 'Flexible reward options']],
        ['Track and Engage', 'Access your dashboard to track referrals and send bulk SMS marketing.', ['Real-time analytics', 'Bulk SMS campaigns']],
    ] as $i => [$title, $text, $bullets])
        <li>
            <p class="text-4xl font-bold text-brand-600" aria-hidden="true">{{ $i + 1 }}</p>
            <h3 class="mt-2 text-xl font-semibold text-text-1">{{ $title }}</h3>
            <p class="mt-2 text-base text-text-2">{{ $text }}</p>
            <ul class="mt-3 list-inside list-disc text-base text-text-2">
                @foreach ($bullets as $bullet)
                    <li>{{ $bullet }}</li>
                @endforeach
            </ul>
        </li>
    @endforeach
</ol>
