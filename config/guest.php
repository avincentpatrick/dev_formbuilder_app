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

        // The challenge endpoint gets its OWN limiter (Increment I8b) rather than sharing `throttle:guest`.
        // Sharing would make every submission cost two requests against `submit_per_token`, silently
        // halving the documented 30/min submit ceiling to 15/min. Issuing a challenge is one HMAC and must
        // stay cheap under attack: the point of the scheme is that the ATTACKER pays for solving, not that
        // we pay for issuing.
        'challenge_per_token' => (int) env('GUEST_CHALLENGE_PER_TOKEN', 60),
        'challenge_per_ip' => (int) env('GUEST_CHALLENGE_PER_IP', 90),
    ],

    // The proof-of-work bot challenge (Increment I8b, App\Support\Guest\GuestChallengeService) — PRD
    // Feature #3's per-form bot-challenge criterion. Off per form by default; these are the deployment-wide
    // parameters an operator tunes, never the form author.
    'challenge' => [
        // Optional explicit signing key. Left null the service derives one from APP_KEY with domain
        // separation ('guest-challenge.v1'), like the three token families above.
        'key' => env('GUEST_CHALLENGE_KEY'),

        // How long a minted challenge stays solvable, in seconds. Short on purpose: the client fetches and
        // solves at SEND time (never at fill time), so even an outbox row queued for days gets a challenge
        // that is milliseconds old. This is a replay window, not a fill window.
        'ttl' => (int) env('GUEST_CHALLENGE_TTL', 300),

        // The search space. The solver tries 0..max until sha256(salt.n) matches, so expected work is
        // max/2 hashes: ~150-300ms under crypto.subtle on a mid-range phone, invisible behind the submit
        // button's loading state. ⚠️ An insecure context (an http:// page embedding the form) has NO
        // crypto.subtle and falls back to a pure-JS SHA-256, which is far slower — lower this for a
        // deployment that expects embedded use, and see challenge.ts for why the fallback must exist.
        'max_number' => (int) env('GUEST_CHALLENGE_MAX_NUMBER', 120000),
    ],
];
