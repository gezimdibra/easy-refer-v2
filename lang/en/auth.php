<?php

/*
 | Authentication language lines. The framework defaults are
 | overridden with the copy inventory's approved strings wherever
 | the framework would otherwise speak to a user (F-03): no
 | framework default text renders on any user-facing surface.
 */

return [
    // AUTH-IN-04: failed login, identical for existing and
    // nonexistent accounts.
    'failed' => 'The email or phone number and password do not match. Please try again.',

    // AUTH-IN-05: throttled login. The inventory string carries no
    // countdown; the seconds/minutes parameters the caller passes
    // are accepted and unused.
    'throttle' => 'Too many attempts. Please wait a few minutes and try again.',
];
