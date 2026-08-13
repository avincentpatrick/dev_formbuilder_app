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

    /*
    |----------------------------------------------------------------------
    | Google — FIRST-PARTY END-USER SIGN-IN ONLY (Increment J3c2, ADR-0017).
    |----------------------------------------------------------------------
    | ⚠️ NOT the Google Sheets CONNECTOR, whose credentials live in
    | `config/connectors.php` under `providers.google_sheets` and are governed by
    | ADR-0009's token-custody rules. Two different Google clients, two different
    | consent screens, two different blast radii: this one reads an identity once
    | and discards the token, that one holds a durable credential that acts as the
    | tenant inside the tenant's own spreadsheet. ADR-0009 §D9 already records that
    | conflating a sign-in block with an unrelated one of the same vendor name is a
    | mistake this file has made before, which is why they are kept apart by name.
    |
    | Both may be left empty: `GoogleSignInGate` hides the button and closes the
    | routes when they are, and the whole flow is exercised in tests against
    | `FakeGoogleIdentityProvider`, so an unconfigured deployment is a supported
    | state rather than a broken one.
    |
    | The redirect is DERIVED rather than configured separately, because Google
    | matches it byte for byte between the authorize step and the token exchange —
    | a third env var is a third chance to get that wrong in production only.
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/auth/google/callback',
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
