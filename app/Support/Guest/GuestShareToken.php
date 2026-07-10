<?php

declare(strict_types=1);

namespace App\Support\Guest;

/**
 * The verified payload of a guest share token (Increment F5, technical-architecture.md §5 layer 9). A
 * {@see GuestShareTokenService} produces this only after the HMAC signature and expiry have passed, so a
 * value of this type is proof the link is authentic and unexpired. It embeds `tenant_id` + `form_id` +
 * `form_version_id` (the exact published version the guest is answering) so the request's Postgres RLS
 * tenant context can be derived without a session or subdomain, and cross-tenant/cross-form replay is
 * signature-checked before any context is established. `rawToken` is retained so the ingest channel can
 * store its sha256 fingerprint on the submission (the raw token is too long for `submissions.guest_token`).
 */
final readonly class GuestShareToken
{
    public function __construct(
        public string $tenantId,
        public string $formId,
        public string $formVersionId,
        public int $expiresAt,
        public string $rawToken,
    ) {}
}
