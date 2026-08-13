<?php

declare(strict_types=1);

namespace App\Services\Sso;

/**
 * The person an assertion names, in this application's own terms (Phase 4, P1b).
 *
 * Two fields, because two is what `users` needs to exist. Everything else the IdP sent stays on
 * {@see SsoAssertion} and is deliberately not carried across: an SP that copied every claim into its user
 * table would be storing HR data it never asked for and cannot erase on request.
 */
final readonly class SsoIdentity
{
    public function __construct(
        /** Lower-cased. `users.email` is the identity key this application resolves on. */
        public string $email,
        /** Never empty — {@see SsoIdentityResolver} falls back to the email's local part. */
        public string $name,
    ) {}
}
