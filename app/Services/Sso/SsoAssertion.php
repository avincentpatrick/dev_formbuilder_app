<?php

declare(strict_types=1);

namespace App\Services\Sso;

/**
 * What a validated assertion actually said (Phase 4, P1b — ADR-0016).
 *
 * A value object rather than the `OneLogin\Saml2\Response` itself, and the boundary is deliberate: nothing
 * downstream of {@see SsoAssertionValidator} should be able to ask the library a question the validator did
 * not already answer. `Response` exposes `getNameId()` alongside `getXMLDocument()`, and a provisioner
 * holding one could reach past every check into the raw document — which is how a validated field and a
 * read field drift apart.
 *
 * `assertionId` travels because the replay ledger keys on it (§D8), not because anything renders it.
 */
final readonly class SsoAssertion
{
    /**
     * @param  array<string, list<string>>  $attributes  as the IdP named them, unmapped — the
     *                                                   `attribute_map` translation is
     *                                                   {@see SsoIdentityResolver}'s job
     */
    public function __construct(
        public string $assertionId,
        public string $nameId,
        public ?string $nameIdFormat,
        public array $attributes,
        public ?string $sessionIndex,
    ) {}

    /**
     * The first value of an attribute, whichever way the IdP named it.
     *
     * Case-insensitive on the NAME, because the same claim arrives as `emailAddress` from ADFS and
     * `http://schemas.xmlsoap.org/…/emailaddress` from Azure AD and `Email` from a hand-rolled IdP, and a
     * tenant admin typing one capitalisation into the attribute map should not silently get nothing.
     * Case is preserved in the VALUE, which is data.
     */
    public function attribute(string $name): ?string
    {
        foreach ($this->attributes as $key => $values) {
            if (mb_strtolower($key) === mb_strtolower($name)) {
                return $values[0] ?? null;
            }
        }

        return null;
    }
}
