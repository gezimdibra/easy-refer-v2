<?php

/*
 * Referral program tunables (spec Section 3). The bounds here are
 * the platform contract: business-configurable values are validated
 * against them at set time (the vesting window accepts only 7 to 14
 * days, OD-3; BILL-43), and the velocity caps are enforced against
 * the business's own configured cap and the platform bound, whichever
 * is tighter.
 */

return [
    // OD-3 (option (b)): referrer rewards vest after this hold so a
    // refunded visit cancels its still-pending reward. Configurable
    // 7 to 14 days; ships at 14.
    'vesting_days_default' => 14,
    'vesting_days_min' => 7,
    'vesting_days_max' => 14,

    // M-4 (rewards expiry design pass, Section 1.3): a referrer
    // reward stays claimable for this many CALENDAR months from
    // the day it becomes available (its available_at), stamped at
    // mint as expires_at and never moved afterward. A PLATFORM
    // CONSTANT, not owner-configurable: the claim window is a
    // trust constant of the platform, like the closure 30 days.
    // The clock pauses while a reward is held (the SA-03 shape),
    // and a release guarantees at least
    // reward_expiry_release_min_days of claimable time.
    'reward_expiry_months' => 12,

    // M-4: the one transactional warning text goes out this many
    // days before a reward's expires_at (pass Section 2.4), and
    // the owner's Action Center row covers the same window.
    'reward_expiry_warning_days' => 30,

    // M-4: the release top-up floor (pass Section 2.5): a reward
    // released from a hold whose date is nearer than this many
    // days out is moved to now + this many days, gated on the
    // reward actually having been held (the releaseReferral
    // abuse discipline).
    'reward_expiry_release_min_days' => 30,

    // Per-business referral velocity caps (spec Section 3 Step 8).
    // The spec names the caps as business-configurable "within
    // platform bounds" without numbering the bounds; the bounds
    // below are the platform contract the settings page validates
    // against (generous enough never to block a legitimate local
    // program, tight enough to bound abuse).
    'velocity_daily_default' => 5,
    'velocity_monthly_default' => 50,
    'velocity_daily_min' => 1,
    'velocity_daily_platform_max' => 100,
    'velocity_monthly_min' => 1,
    'velocity_monthly_platform_max' => 1000,

    // Risk scoring (spec Section 3.1). A combined-signal score at or
    // above the hold threshold withholds minting or claimability
    // pending owner review; a single weak signal, such as a shared
    // IP address, never holds on its own.
    'risk_hold_threshold' => 50,
    'risk_points' => [
        'device_signal_reuse' => 50,
        'self_referral_phone' => 100,
        'velocity_cap_hit' => 50,
        'token_guess_burst' => 50,
        // The one-hour minimum-age soft signal (money fix
        // train, audit 2.2): a redemption inside the referral's
        // first hour is recorded as a review signal only. Its
        // points sit far below the hold threshold on purpose:
        // the signal never holds or blocks anything on its own.
        'referral_age_under_one_hour' => 10,
    ],

    // The minimum-age signal window, in minutes: a redemption
    // younger than this records the signal above. Signal only,
    // never a gate (the v1 provenance decision, kept in v2).
    'minimum_age_signal_minutes' => 60,

    // The fresh minimum visit window after a held referral is
    // released from review (SA-03, Amendment 35): a held
    // referral does not expire, and on release its remaining
    // window is topped up to at least the lesser of this many
    // days and the business's own redemption_window_days, so
    // a released friend always gets a usable window and never
    // one longer than the business's own fresh window when
    // that window is the shorter of the two.
    'held_release_min_window_days' => 7,

    // The scan-to-redemption minimum gap, in minutes (owner
    // rulings 2026-10-27 and 2026-10-29): a referral's
    // redemption cannot complete until this long after the
    // referrer's share link came into existence (their
    // membership creation, the store-QR scan moment). It is
    // enforced ONLY at redemption, inside RedemptionService:
    // the friend's claim completes immediately at any time and
    // no claim surface names the gap. Inside the window the
    // redemption is refused, the referral stays pending with
    // its token unconsumed, and the same code completes on a
    // retry after the time. It keeps two people who walked in
    // together from scanning, sharing, and collecting in one
    // burst, while a link shared ahead of the visit never
    // notices it. Purely timestamp math on the anchor, so
    // referrals created before this rule's enforcement point
    // moved are handled identically.
    'claim_min_gap_minutes' => 25,

    // Phone verification budgets (spec Section 10.4, twilio-blueprint
    // Section 1.2). Lifetime counters are per canonical phone,
    // hourly per phone and per IP, per business per hour; checks are
    // bounded per pending verification. Database counters, never
    // session counters.
    'verify' => [
        'starts_per_phone_per_hour' => 3,
        'starts_per_ip_per_hour' => 10,
        'starts_per_business_per_hour' => 50,
        'checks_per_pending' => 5,
        'resend_cooldown_seconds' => 60,
        'starts_per_flow_session' => 4,
        'code_ttl_minutes' => 10,
    ],

    // Token-resolution budget (spec Section 10.4): failed resolutions
    // per IP per hour, platform default.
    'token_resolution_budget_per_ip_hour' => 100,

    // Anomaly rules (spec Section 3.1). Thresholds are platform
    // constants in v2; a hit raises risk and can hold the minted
    // reward, it never hard-blocks a redemption by itself. Open
    // hours are not configured per business in v2, so the
    // out-of-hours rule is exempt by default until hours exist.
    'anomaly' => [
        'burst_multiple' => 3,
        'burst_minimum' => 10,
        'conversion_ratio' => 0.90,
        'conversion_minimum_enrollments' => 20,
        'unattributed_ratio' => 0.50,
        'unattributed_minimum' => 10,
        'unattributed_week_jump_points' => 20,
    ],

    // ID-05 (Amendment 48): the review ask's impression
    // cooldown, per (business, customer), measured from the
    // last recorded review_ask_shown impression and shared
    // across both receipt surfaces. Time is the only thing
    // that resets it. The number renders in copy ([REVIEW-05]),
    // so a config change moves the copy with it.
    'review_ask_cooldown_days' => 90,

    // The cooling window after a number link (spec Section 3.1,
    // EC-16): rewards earned before the link are claimable only
    // after it, with a lookup step-up for dormant accounts.
    'number_link_cooling_days' => 7,

    // Consent wording version recorded with every consent capture
    // (spec Section 7.2, REF-07): bump when the inventory's consent
    // copy changes.
    'consent_wording_version' => 'v1',

    // OD-10 default: the enrollment and claim forms ship WITHOUT the
    // optional customer email field. The published privacy policy's
    // data inventory is generated from this same flag (spec Section
    // 2.4), so the policy can never describe collecting an email the
    // shipped form does not collect.
    'customer_email_field' => env('CUSTOMER_EMAIL_FIELD', false),
];
