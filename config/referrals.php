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

    // Per-business referral velocity caps (spec Section 3 Step 8).
    'velocity_daily_default' => 5,
    'velocity_monthly_default' => 50,

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
    ],

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
