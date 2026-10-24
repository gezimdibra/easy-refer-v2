@extends('layouts.marketing')

@section('title', 'Refund Policy: Easy Refer')

@section('meta_description', 'The Easy Refer refund policy: how per-visit charges, reward fees, and disputes are handled.')

@section('content')
    {{-- The refund page (spec Section 2.4): v1's structure with the
         corrected processor names (Square and Twilio only) and the
         per-visit amount rendered from the plan table (REFUND-02). --}}
    <section class="shell section-pad">
        <div class="max-w-[640px]">
            <h1 class="type-title-1 text-text-1">Refund Policy</h1>

            <h2 class="mt-10 type-title-1 text-text-1">Subscription and service fees</h2>
            <p class="mt-3 text-base text-text-2">Subscription fees are refundable only for billing errors or duplicate charges, or when a failure of core services caused by a system error is reported within 14 days.</p>
            <p class="mt-3 text-base text-text-2">Not refundable:</p>
            <ul class="mt-2 list-inside list-disc space-y-1 text-base text-text-2">
                <li>Change of mind</li>
                <li>Partial months after cancellation</li>
                <li>Failure to use the platform</li>
            </ul>
            <p class="mt-3 text-base text-text-2">Refund requests include business name, registered email, reason, and evidence.</p>

            <h2 class="mt-10 type-title-1 text-text-1">Referral visit charges</h2>
            <p class="mt-3 text-base text-text-2">Businesses are charged ${{ number_format($plan->per_referral_cents / 100, 2) }} per verified referral visit.</p>
            <p class="mt-3 text-base text-text-2">A visit charge is refundable when a platform error prevented verification, or for proven fraudulent referrals. Disputes must be raised within 30 days with transaction details.</p>

            <h2 class="mt-10 type-title-1 text-text-1">SMS promotion charges</h2>
            <p class="mt-3 text-base text-text-2">SMS promotion charges are usage-based via Twilio and non-refundable except for failed delivery caused by a verified system issue.</p>

            <h2 class="mt-10 type-title-1 text-text-1">Disputes</h2>
            <p class="mt-3 text-base text-text-2">We acknowledge disputes within 2 business days and typically resolve them within 10 business days (complex cases may take up to 30 days). Approved refunds are issued within 7 business days.</p>
            <p class="mt-3 text-base text-text-2">Filing a chargeback without first attempting resolution with us may lead to suspension or termination.</p>
        </div>
    </section>
@endsection
