<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bulk SMS campaigns (spec Sections 10.3, 10.4)
    |--------------------------------------------------------------------------
    |
    | The anti-abuse budgets around owner campaigns. Money itself
    | is not configured here: the per-segment price is OD-1's
    | billing.sms_segment_price_cents. Raising any of these is a
    | platform-admin config edit, never a code change.
    |
    */

    // Seconds between one business's campaign sends at dispatch:
    // 1 is the sole-proprietor 10DLC sender-pool rate. The
    // production number rides a verified toll-free pool, which
    // allows more; raising the pace is a wiring-time config edit.
    'send_interval_seconds' => (int) env('CAMPAIGN_SEND_INTERVAL_SECONDS', 1),

    // Per-business daily campaign cap (spec Section 10.4),
    // enforced at queue time in the send: what does not fit is
    // recorded as not sent, never silently dropped.
    'daily_cap' => (int) env('CAMPAIGN_DAILY_CAP', 1000),

    // Platform-global daily campaign ceiling (spec Section 10.4,
    // threat G-03): at the ceiling, campaign sends pause
    // platform-wide until a platform admin raises it.
    'platform_daily_ceiling' => (int) env('CAMPAIGN_PLATFORM_DAILY_CEILING', 10000),

];
