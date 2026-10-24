<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Spec Section 9. Credentials live only in env, never in code, the
    // database, logs, or any client response (audit C4, C6). The API
    // version was pinned at Phase 4 kickoff: 2026-09-16, the version
    // the installed square/square SDK targets; SQUARE_VERSION in the
    // environment overrides it only as a deliberate, reviewed act.
    'square' => [
        'environment' => env('SQUARE_ENVIRONMENT', 'sandbox'),
        'access_token' => env('SQUARE_ACCESS_TOKEN'),
        'application_id' => env('SQUARE_APPLICATION_ID'),
        'location_id' => env('SQUARE_LOCATION_ID'),
        'webhook_signature_key' => env('SQUARE_WEBHOOK_SIGNATURE_KEY'),
        'version' => env('SQUARE_VERSION', '2026-09-16'),
        'timeout' => 10,
    ],

    // Spec Section 8.3: the per-account aggregate login defense requires
    // a server-verified Cloudflare Turnstile challenge once an account
    // crosses the failure threshold. Empty keys disable the challenge
    // widget; the account lock itself always applies.
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    // Spec Section 10. API key auth, never the account auth token.
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'api_key_sid' => env('TWILIO_API_KEY_SID'),
        'api_key_secret' => env('TWILIO_API_KEY_SECRET'),
        'verify_service_sid' => env('TWILIO_VERIFY_SERVICE_SID'),
        'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
        // Used only to validate inbound webhook signatures (spec
        // Section 10.2); outbound calls never authenticate with it.
        'webhook_auth_token' => env('TWILIO_AUTH_TOKEN'),
        // The exact public URL Twilio is configured to call. When
        // set, inbound signatures validate against it instead of
        // the request URL the proxy delivered, so a proxy or mount
        // change cannot silently 403 every callback (security
        // verifier m-5).
        'webhook_url' => env('TWILIO_WEBHOOK_URL'),
        // The marketing pool (spec Section 10.1): bulk sends never
        // ride the transactional pool. Read by the campaign sender.
        'marketing_messaging_service_sid' => env('TWILIO_MARKETING_MESSAGING_SERVICE_SID'),
    ],

];
