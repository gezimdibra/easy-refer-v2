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

];
