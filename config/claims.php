<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Approved marketing claims (OD-6, closed by the owner 2026-10-23)
    |--------------------------------------------------------------------------
    |
    | The single source for every marketing figure the public pages
    | present as a platform result (landing-page-spec.md Section 5.1).
    | Templates read these values and never hardcode a figure, the
    | same discipline the plan table imposes on prices, so a number
    | can never drift between pages again (the v1 defect: 400+ on one
    | page, 2+ on another). Anything not listed here is not an
    | approved claim and must not render (AP-07). A change to any
    | value is a new owner decision, recorded in copy-inventory.md
    | Section 13 before it lands here.
    |
    */

    'new_customers_per_year' => '400+',
    'higher_purchase' => '30%',
    'retention_rate' => '2x',
    // The two platform count claims ('businesses' => '400+'
    // and 'successful_referrals' => '80+') were removed in the
    // pre-launch money fix train (coordinator ruling
    // 2026-10-25): rendered as achieved platform results, they
    // are false on day one at launch, when the platform has no
    // real businesses yet. They return here, with their
    // renderers, only when the counts are true. The approved
    // per-business outcome claims above stay as approved.
    'first_visits_free' => '5',
    'founded' => 'Founded in 2023',

];
