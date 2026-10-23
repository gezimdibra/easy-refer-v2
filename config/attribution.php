<?php

/*
 * Attribution and analytics configuration (spec Section 16). With
 * no IDs configured, marketing pages render no analytics markup at
 * all: no empty script shells. The er_attrib cookie is first-party,
 * functional, set server-side, and deleted when the visitor declines
 * on the consent banner; capture stops with it.
 */

return [
    'ga4_id' => env('GA4_MEASUREMENT_ID'),
    'meta_pixel_id' => env('META_PIXEL_ID'),

    'cookie_name' => 'er_attrib',
    'cookie_days' => 90,

    'consent_cookie_name' => 'er_consent',
];
