<?php

declare(strict_types=1);

/*
 | Guest runtime (Increment F5) — the public share-link submission channel. All values are env-overridable
 | so an operator can tune the link lifetime and the anti-abuse limits per deployment without a code change.
 */

return [
    // The stateless HMAC share token (App\Support\Guest\GuestShareTokenService).
    'share_token' => [
        // How long a minted link stays valid, in seconds. The token is re-minted on every /f/{slug} visit,
        // so this is really the maximum length of a single fill session (default: 24h).
        'ttl' => (int) env('GUEST_SHARE_TOKEN_TTL', 86400),

        // Optional explicit signing key. Left null the service derives one from APP_KEY with domain
        // separation (rotates with the app key). Set only to rotate the guest key independently.
        'key' => env('GUEST_SHARE_TOKEN_KEY'),
    ],

    // The durable resume token (Increment H9b, App\Support\Guest\GuestShareTokenService::mintResume). Unlike
    // the share token it is scoped to one draft submissions.id and lives long enough to survive a device
    // change / cleared local storage. Signed with a SEPARATE domain-separated key so it can never be confused
    // with a share token.
    'resume_token' => [
        // How long a resume link stays valid, in seconds (default: 30d — matches the draft's stamped
        // draft_expires_at TTL; the tenant-configurable override is H10). Stamped once at mint time.
        'ttl' => (int) env('SUBMISSION_RESUME_TOKEN_TTL', 2592000),

        // Optional explicit signing key. Left null the service derives one from APP_KEY with domain
        // separation ('guest-resume-token.v1'). Set only to rotate the resume key independently.
        'key' => env('SUBMISSION_RESUME_TOKEN_KEY'),
    ],

    // Rate limits (requests/minute). The submit + schema endpoints are limited per token AND per IP
    // (technical-architecture.md §7.2); the mint endpoint is limited per IP.
    'rate_limit' => [
        'submit_per_token' => (int) env('GUEST_SUBMIT_PER_TOKEN', 30),
        'submit_per_ip' => (int) env('GUEST_SUBMIT_PER_IP', 60),
        'mint_per_ip' => (int) env('GUEST_MINT_PER_IP', 30),
    ],
];
