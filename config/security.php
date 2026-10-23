<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Password policy (spec Section 8.2)
    |--------------------------------------------------------------------------
    |
    | Minimum 12 characters, generous maximum, no composition rules, no
    | forced rotation. Breached-password screening uses the Have I Been
    | Pwned k-anonymity API; only the first five characters of the SHA-1
    | of a candidate password ever leave the server.
    |
    */

    'password' => [
        'min_length' => 12,
        'max_length' => 255,
        'breach_screening' => env('PASSWORD_BREACH_SCREENING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Login throttling (spec Section 8.3)
    |--------------------------------------------------------------------------
    |
    | Server-side and persistent, keyed by identifier plus IP, never by
    | session. After repeated lockouts inside an hour the lockout window
    | escalates from one minute to fifteen minutes.
    |
    */

    'login_throttle' => [
        'max_attempts' => 5,
        'decay_seconds' => 60,
        'lockouts_before_escalation' => 3,
        'escalated_decay_seconds' => 900,
        'lockout_tracking_seconds' => 3600,
        // Per-account aggregate defense (spec Section 8.3, threat G-06):
        // failures against one account counted across all source IPs.
        'account_max_failures' => 25,
        'account_window_seconds' => 3600,
        'account_lock_seconds' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Session lifetime (spec Section 8.4)
    |--------------------------------------------------------------------------
    |
    | Idle timeout is the framework session lifetime (SESSION_LIFETIME,
    | 120 minutes). The absolute cap below is enforced by middleware from
    | the login timestamp stored in the session.
    |
    */

    'session' => [
        'absolute_lifetime_minutes' => 60 * 24,
        'rotation_minutes' => 30,
    ],

];
