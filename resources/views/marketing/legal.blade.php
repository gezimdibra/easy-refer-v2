@extends('layouts.marketing')

@section('title', 'Legal: Easy Refer')

@section('meta_description', 'Easy Refer legal documents: privacy policy, terms of service, EULA, and compliance information.')

@section('content')
    {{-- The legal page keeps v1's anchored structure (LEGAL-03)
         with the pre-approved corrections (LEGAL-01, LEGAL-02): the
         processors are Square and Twilio only, and the privacy data
         inventory matches the v2 schema and the shipped form
         configuration exactly. The full prose is pending the owner's
         legal review (inventory Section 5). --}}
    <section class="shell section-pad">
        <div class="max-w-[640px]">
            <h1 class="type-title-1 text-text-1">Legal</h1>

            <h2 id="privacy-policy" class="mt-10 type-title-1 text-text-1">Privacy Policy</h2>
            <p class="mt-3 text-base text-text-2">We collect the information the product needs to run a referral program, and nothing more. For customers of a business, that is: {{ $customerDataInventory }}. For business owners, it also includes the email address on the account. We never sell personal information.</p>
            {{-- P-04 (release 08b): the attribution disclosure was
                 one 92-word paragraph, the page's worst mobile
                 block. It splits at its natural sentence seam into
                 two, and subheadings break the privacy block so no
                 run exceeds three paragraphs. No wording changes
                 beyond the split; the subheads are structural
                 labels only. --}}
            <h3 class="mt-6 type-title-3 text-text-1">How we attribute signups</h3>
            <p class="mt-3 text-base text-text-2">When a business signs up, we record how it found us in the business's account: the campaign source, medium, campaign, term, and content values, any Google, Facebook, or TikTok click ID, the page first visited, the referring site's host, and the time.</p>
            <p class="mt-3 text-base text-text-2">A first-party cookie named er_attrib holds those same values on the visitor's browser for 90 days so the signup can be attributed; it is a functional cookie, it records campaign parameters only (no names, emails, or phone numbers), and declining on the cookie banner deletes it and stops further capture.</p>
            <h3 class="mt-6 type-title-3 text-text-1">Payments, messages, and measurement</h3>
            <p class="mt-3 text-base text-text-2">Payments are processed by Square and text messages and phone verification by Twilio. Ad measurement, only with your consent, uses Google Analytics and the Meta Pixel. Card details never touch our servers: we do not store payment information ourselves.</p>
            <p class="mt-3 text-base text-text-2">Data requests: <a class="font-semibold text-brand-700 underline" href="mailto:support@easy-refer.com">support@easy-refer.com</a>. You can request a copy or deletion of your data any time.</p>

            <h2 id="terms-of-service" class="mt-10 type-title-1 text-text-1">Terms of Service</h2>
            <ul class="mt-3 list-inside list-disc space-y-1 text-base text-text-2">
                <li>Provide accurate information.</li>
                <li>Keep your credentials secure.</li>
                <li>No illegal or unauthorized use.</li>
                <li>Fake referrals and rewards manipulation are strictly prohibited.</li>
                <li>Violations can lead to suspension.</li>
            </ul>

            <h2 id="eula" class="mt-10 type-title-1 text-text-1">EULA</h2>
            <p class="mt-3 text-base text-text-2">The end user license terms for the Easy Refer service are part of the Terms of Service above and apply to every account and every use of the service.</p>

            <h2 id="compliance" class="mt-10 type-title-1 text-text-1">Compliance</h2>
            <p class="mt-3 text-base text-text-2">Easy Refer is built to comply with GDPR, CCPA, and PCI DSS. We do not store payment information ourselves.</p>

            <h2 class="mt-10 type-title-1 text-text-1">Refunds</h2>
            <p class="mt-3 text-base text-text-2">Refund requests must be made within 14 days of payment. Approved refunds are processed within 7 to 10 business days. Contact <a class="font-semibold text-brand-700 underline" href="mailto:support@easy-refer.com">support@easy-refer.com</a>.</p>
            <p class="mt-3 text-base"><a class="font-semibold text-brand-700 underline" href="{{ route('refund') }}">Refund Policy</a></p>
        </div>
    </section>
@endsection
