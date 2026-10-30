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

    // Per-recipient campaign frequency cap (F1, Amendment 45):
    // a HARD cap per business per phone — at most
    // recency_max_touches campaign touches in any rolling
    // recency_window_days, with at least recency_spacing_days
    // between touches; no owner override; campaign sends only
    // (transactional messages never count and are never
    // blocked). Tuning is governed by the design pass's
    // keep/tune rule (review at 90 days + >=100 real campaigns;
    // loosening requires re-running the consent-disclosure
    // check first). A config change never rewrites stored
    // history.
    'recency_spacing_days' => (int) env('CAMPAIGN_RECENCY_SPACING_DAYS', 7),
    'recency_window_days' => (int) env('CAMPAIGN_RECENCY_WINDOW_DAYS', 30),
    'recency_max_touches' => (int) env('CAMPAIGN_RECENCY_MAX_TOUCHES', 4),

    // Idea Train 1 (Amendment 46). ID-01 Phase 1 is
    // OWNER-DECLARED only (owner ruling 2026-10-28): the
    // slow-day prompt fires on the weekday the owner picked,
    // never on a detected day. Its prompt spacing gate: no
    // prompt when the business sent any campaign inside this
    // many days (the prompt's own law, distinct from F1).
    'slow_day_prompt_spacing_days' => (int) env('CAMPAIGN_SLOW_DAY_PROMPT_SPACING_DAYS', 7),
    'prompt_min_sendable' => (int) env('CAMPAIGN_PROMPT_MIN_SENDABLE', 5),

    // ID-03 win-back: the lapse window (a proven referrer whose
    // last referral was created this many days ago or more is
    // "quiet"), the per-(business, phone) win-back cooldown
    // (binding in addition to F1), and the prompt's
    // business-level post-send silence.
    'winback_lapse_days' => (int) env('CAMPAIGN_WINBACK_LAPSE_DAYS', 45),
    'winback_cooldown_days' => (int) env('CAMPAIGN_WINBACK_COOLDOWN_DAYS', 90),
    'winback_prompt_silence_days' => (int) env('CAMPAIGN_WINBACK_PROMPT_SILENCE_DAYS', 30),

    // Idea Train 2 (Amendment 48; ID-02): top-referrer
    // recognition. The straight-face floor: no title, no card,
    // and no text unless the previous complete business-local
    // month's leader settled at least this many visits. The
    // card window: days 1..N of the current month, for the
    // previous month's standings.
    'top_referrer_floor' => (int) env('CAMPAIGN_TOP_REFERRER_FLOOR', 3),
    'top_referrer_window_days' => (int) env('CAMPAIGN_TOP_REFERRER_WINDOW_DAYS', 14),
    // ID-06 second-code prompt (Amendment 49): the judged
    // window — a second distinct placement printed within
    // this many days of the first settled visit — and the
    // row's hard expiry, measured from the settlement instant
    // (release time for a held-first visit). The [SC-01]
    // "just came in" wording is true only because this window
    // is hard; extending it requires striking that word.
    'second_placement_window_days' => (int) env('CAMPAIGN_SECOND_PLACEMENT_WINDOW_DAYS', 14),


    // The dormant slow-day analyzer's floor (ID-01 section
    // 1.3): the named data floor for the post-launch candidate.
    // Recorded and logged in this build; it drives NO surface.
    'slow_day_floor_weeks' => (int) env('CAMPAIGN_SLOW_DAY_FLOOR_WEEKS', 8),
    'slow_day_floor_min_visits' => (int) env('CAMPAIGN_SLOW_DAY_FLOOR_MIN_VISITS', 56),
    'slow_day_floor_margin_percent' => (int) env('CAMPAIGN_SLOW_DAY_FLOOR_MARGIN_PERCENT', 60),

];
