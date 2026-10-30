<?php

/**
 * G6 DEMO PHASE 1 (Amendment 63; demo-phase1-design-pass.md).
 * Every number here is a ruled number from the pass, not a
 * default: the 15-minute FIXED session (Section 11.1, never
 * sliding), the start guards re-derived for a zero-SMS surface
 * (Section 12), and the code attempts budget, which is the real
 * verify budget's own value so the demo's error behaviour is the
 * real flow's in kind.
 */
return [
    // The demo session cookie: opaque token only; the record it
    // keys lives in demo_sessions.
    'cookie' => 'easy_refer_demo',

    // The 7-day signup attribution cookie (Section 8, C-1).
    'attribution_cookie' => 'easy_refer_demo_attr',
    'attribution_days' => 7,

    // 15-minute FIXED expiry from creation (Section 11.1).
    'session_ttl_minutes' => 15,

    'business_name' => 'Juniper Café',
    'business_slug' => 'juniper-cafe',
    // The seed's fallback if a real business already carries the
    // name (Section 13.7: the flag, never the name, carries the
    // rules, so a rename touches no logic).
    'business_name_fallback' => 'Juniper Café (Demo Shop)',

    // The example-number escape (Section 5.3): a reserved
    // fictional-range number, displayed as-is.
    'example_phone_display' => '(555) 010-0199',
    'example_phone_e164' => '+15550100199',

    'code' => [
        'length' => 6,
        // The real flow's per-pending check budget
        // (config/referrals.php verify.checks_per_pending).
        'max_attempts' => 5,
    ],

    'guards' => [
        'starts_per_ip_per_hour' => 10,
        'starts_per_ip_per_day' => 30,
        'global_starts_per_day' => 2000,
        'alert_starts_per_day' => 500,
    ],
];
