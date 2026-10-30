<?php

/**
 * G1 CLAIMABLE PRE-MADE SHOPS (g1-claimable-design-pass.md).
 *
 * The claimable page's clocks and budgets, named once here so the
 * design pass's numbers are the code's numbers: 60-day page life
 * (P7), 30-day snapshot freshness before a send, 7-day restore
 * window and pending-review lock, 12-month suppression, gate code
 * budgets (3 sends / 24h, 5 wrong entries per code, 24h attempt
 * lock), review hand-off validity 72h, denied-review identity
 * lockout 30 days, second-expiry quiet list 12 months.
 */
return [
    'page_lifetime_days' => 60,
    'snapshot_fresh_days' => 30,
    'restore_window_days' => 7,
    'review_lock_days' => 7,
    'suppression_months' => 12,
    'quiet_months' => 12,
    'review_handoff_hours' => 72,
    'denied_identity_lockout_days' => 30,
    'gate_sends_per_day' => 3,
    'gate_wrong_entries_per_code' => 5,
    'gate_lock_hours' => 24,
];
