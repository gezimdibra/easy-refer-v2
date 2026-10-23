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
    | Account unlock links (spec Section 8.3, Amendment 5)
    |--------------------------------------------------------------------------
    |
    | When the aggregate defense locks an account, the owner also
    | receives a one-time unlock link by email. The token is stored
    | only as a hash, works once, and expires with the lock window, so
    | the legitimate owner always has a way back in, Turnstile keys or
    | not. Unlocking clears the aggregate lock and failure counter
    | only; per-IP throttles and escalation state are untouched.
    |
    */

    'account_unlock' => [
        'token_ttl_seconds' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Signup budget (spec Section 8.3)
    |--------------------------------------------------------------------------
    |
    | Signup carries a per-IP budget at the route and this
    | per-identifier budget in the form request, so rotating source
    | addresses cannot multiply attempts against one email or phone.
    |
    */

    'signup_throttle' => [
        'per_identifier_max_attempts' => 10,
        'per_identifier_window_seconds' => 3600,
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

    /*
    |--------------------------------------------------------------------------
    | Customer flow sessions (spec Sections 3 and 8.4, AUTH-40)
    |--------------------------------------------------------------------------
    |
    | The public /refer flow carries its own 30-minute session,
    | separate from the owner session: its own cookie, its own
    | server-side record, no shared state. The cookie name is
    | distinct from the owner session cookie (er2_session), and the
    | owner cookie grants no shortcut inside the customer flow.
    |
    */

    'flow' => [
        'cookie' => env('FLOW_COOKIE_NAME', 'er2_flow'),
        'ttl_minutes' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-factor authentication (spec Section 8.6, OD-9)
    |--------------------------------------------------------------------------
    |
    | Policy per OD-9: staging runs option (a), optional for business
    | owners and mandatory for platform admin accounts. The admin half
    | of the policy binds when the Phase 3 console introduces admin
    | accounts; the flag lives here so the policy has one home.
    |
    */

    'two_factor' => [
        'owner_required' => false,
        'admin_required' => true,
        'recovery_code_count' => 10,
        // A pending enrollment (secret stored, never confirmed)
        // dies this long after it was started; confirmation after
        // the window is refused and the pending secret is cleared
        // (finding N-2).
        'pending_ttl_seconds' => 1800,
    ],

    /*
    |--------------------------------------------------------------------------
    | Step-up authentication (spec Section 8.7)
    |--------------------------------------------------------------------------
    |
    | The money-moving and account-ending actions require a fresh
    | verification (password re-entry, or a TOTP code where 2FA is
    | enrolled) completed inside this window.
    |
    */

    'step_up' => [
        'window_seconds' => 600,
    ],

];
