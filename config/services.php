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
    // version pins at Phase 4 kickoff via SQUARE_VERSION; empty means
    // the SDK default applies until then.
    'square' => [
        'environment' => env('SQUARE_ENVIRONMENT', 'sandbox'),
        'access_token' => env('SQUARE_ACCESS_TOKEN'),
        'application_id' => env('SQUARE_APPLICATION_ID'),
        'location_id' => env('SQUARE_LOCATION_ID'),
        'webhook_signature_key' => env('SQUARE_WEBHOOK_SIGNATURE_KEY'),
        'version' => env('SQUARE_VERSION'),
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
    ],

];
