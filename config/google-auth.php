<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Google sign-in policy (Increment J3c2, ADR-0019)
|--------------------------------------------------------------------------
| The knobs, kept apart from the CREDENTIALS in `config/services.php` for the same reason `config/saml.php`
| is kept apart from a connection's own settings: these are decisions about the flow, and they must be
| readable and changeable without touching anything that holds a secret.
|
| ⚠️ NOTHING HERE IS A CREDENTIAL. `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` live in `services.google`.
*/

return [

    'state' => [
        /*
         * The signing key for the `state` token. Left empty, it is DERIVED from APP_KEY with a domain
         * separator (see AppServiceProvider), which is what keeps a connector state from ever validating
         * here and vice versa. Set this only to rotate the sign-in family independently of APP_KEY.
         *
         * ⚠️ Rotating it invalidates every consent screen currently open, which is a ~10 minute window
         * rather than a session-length one.
         */
        'key' => env('GOOGLE_SIGNIN_STATE_KEY'),

        /*
         * How long a consent screen may stay open. An interactive prompt, not a share link — the same
         * ~10 minutes ADR-0009 §D3 chose, for the same reason.
         */
        'ttl_seconds' => (int) env('GOOGLE_SIGNIN_STATE_TTL', 600),
    ],

    'handoff' => [
        /*
         * How long the single-use hop from the central callback back to the tenant host stays redeemable.
         * SHORT on purpose: it is one browser redirect, and the token travels in a URL path where it lands
         * in history and any proxy log. Sixty seconds is generous for a redirect and mean for anything
         * that had to be copied out of a log first.
         */
        'ttl_seconds' => (int) env('GOOGLE_SIGNIN_HANDOFF_TTL', 60),
    ],

    'requests' => [
        /*
         * The write-path bound on `google_auth_requests` (ADR-0019 §D7).
         *
         * ⚠️ TRIMMED IN THE SAME CALL AS THE INSERT, NEVER BY A SCHEDULED JOB. `routes/console.php`
         * records that nothing runs the scheduler on the production box, so a nightly prune would be a
         * bound that exists in the repository and not on the machine — and the mint endpoint is
         * unauthenticated.
         *
         * ⚠️ The cap is applied as "not among the newest N", NEVER "older than the Nth". A grinder writes
         * many rows inside one second, and a timestamp comparison keeps every tied row — i.e. it fails at
         * exactly the volume it exists for.
         */
        'max_rows_per_tenant' => (int) env('GOOGLE_SIGNIN_MAX_ROWS', 500),

        'retention_days' => (int) env('GOOGLE_SIGNIN_RETENTION_DAYS', 7),
    ],

];
