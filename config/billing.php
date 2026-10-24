<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Billing (OD-1, approved by the owner 2026-10-23)
    |--------------------------------------------------------------------------
    |
    | One plan: Pay Per Visit. No monthly fee, no tiers. The amounts are
    | configuration, never literals in billing code, so a pricing change
    | is a config edit. The charging engine itself lands in Phase 4;
    | Phase 1 consumes only the signup credit below.
    |
    */

    'plan' => 'pay_per_visit',
    'price_per_verified_visit_cents' => 200,
    'monthly_cap_cents' => 9900,
    'free_visits_at_signup' => 5,
    'sms_segment_price_cents' => 2,

    /*
    |--------------------------------------------------------------------------
    | Charge execution (Phase 4, spec Section 9.5)
    |--------------------------------------------------------------------------
    |
    | Until the Phase 6 launch approval, charges are disabled at
    | the provider boundary: the engine computes batches and
    | stores them as simulated billing_records, and no Square call
    | is ever made. Enabling charges is a launch-gate act, not a
    | code change. Square's minimum charge is $1: a month whose
    | billable total is below it carries forward instead.
    |
    */

    'charges_enabled' => env('BILLING_CHARGES_ENABLED', false),
    'minimum_charge_cents' => 100,

];
