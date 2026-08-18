<?php

declare(strict_types=1);

namespace App\Enums;

use App\Http\Middleware\PublicRuntimeSecurityHeaders;
use App\Http\Middleware\VerifyGuestBotChallenge;
use App\Support\Guest\GuestChallengeService;

/**
 * The bot-challenge mechanism a form requires of a guest before it will accept a submission — Increment
 * I8b, closing PRD Feature #3's last acceptance criterion.
 *
 * ── ⚠️ THIS ENUM IS THE PROVIDER SEAM. THERE IS DELIBERATELY NOTHING ELSE ──────────────────────────────
 * No `ChallengeDriver` interface, no container binding, no `config/challenge.php` driver map, no factory.
 * {@see GuestChallengeService} decides the mechanism with a `match` that today has one arm, and a future
 * provider adds a case plus an arm — one file, one commit.
 *
 * The asymmetry is the whole argument for spending an enum here rather than a boolean: **a column type is
 * expensive to change on a live table, an interface is cheap to add later.** So the type is chosen for the
 * future and the indirection is not built until a second implementation exists to justify it.
 *
 * ── Why proof-of-work and not a third-party CAPTCHA ───────────────────────────────────────────────────
 * docs/security-threat-model.md §4 already specified it: *"an additional, optional, tenant-configurable
 * CAPTCHA/proof-of-work challenge … not enabled by default"*. Beyond that, reCAPTCHA/Turnstile/hCaptcha
 * each need a site key and secret from the tenant's own account — an input only the user possesses, which
 * would have made this row a blocker — and each needs `script-src` + `frame-src` added to
 * {@see PublicRuntimeSecurityHeaders}, whose test asserts the ABSENCE of those
 * directives as a deliberate tripwire. They also cannot work offline-first, which the outbox requires.
 *
 * A honeypot field was considered and rejected: the guest ingest is a PUBLIC, DOCUMENTED JSON API whose
 * request body is shipped in `api-client.ts` and again in `openapi.json`, so a bot writes eight lines of
 * curl and never parses a DOM. A decoy input is invisible to the only attacker there is.
 */
enum FormBotChallenge: string
{
    /** No challenge. The default, and what every form created before I8b keeps. */
    case Off = 'off';

    /**
     * A hash-based proof of work, verified by {@see VerifyGuestBotChallenge} on submission only.
     *
     * Deliberately NOT on schema reads (the service worker caches them), attachments (one puzzle per file)
     * or draft autosave (which fires repeatedly). Submit is the only write that creates a finalized
     * response, so it is the only place the cost belongs.
     */
    case ProofOfWork = 'proof_of_work';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
