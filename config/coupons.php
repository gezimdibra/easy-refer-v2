<?php

return [
    /*
     * ID-11 direct coupons (S3; design pass Section 4.2). The
     * claim cap is the owner's discount-liability ceiling and
     * the platform's verification-cost ceiling in one number:
     * default 100, selectable steps, hard max 500. Expiry is a
     * property of the batch, printed on the flyer: default 30
     * days, selectable steps, hard range 7 to 90.
     */
    'claim_cap_options' => [25, 50, 100, 250],
    'claim_cap_default' => 100,
    'claim_cap_hard_max' => 500,

    'expiry_day_options' => [7, 14, 30, 60, 90],
    'expiry_days_default' => 30,

    // One claim per verified phone per business per rolling
    // window, across all batches (pass Section 4.2 rule 2).
    'per_business_window_days' => 30,

    // The platform default offer (pass Section 6.3 prefill
    // source 3), used only when neither the business's own
    // first-visit offer nor a starter pack exists.
    'default_offer' => '10% off your first visit',

    // Destination label chips (pass Section 6.3): one tap picks
    // the label, so the common placements never need typing.
    'label_chips' => [
        'Gym',
        'Laundromat',
        'Partner shop counter',
        'Community board',
        'Bus stop or street',
        'School or office',
        'Receipts and bags',
    ],

    // Batch flag thresholds (pass Section 4.4): burst is 3x the
    // batch's own trailing hourly rate with a floor of mints;
    // conversion is unlocks-to-scans at volume.
    'flag_burst_multiplier' => 3,
    'flag_burst_min_mints' => 10,
    'flag_conversion_ratio' => 0.90,
    'flag_conversion_min_scans' => 20,
];
