<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Models\SsoConnection;
use Illuminate\Support\Str;

/**
 * Reads a tenant's `attribute_map` and turns a validated assertion into an {@see SsoIdentity} (P1b).
 *
 * ── EMAIL IS THE IDENTITY KEY, AND THERE ARE EXACTLY TWO PLACES IT MAY COME FROM ────────────────────
 * `config/saml.php` states it plainly: "this application's identity key IS the email address — `users.email`
 * is what a JIT provision matches on and what an invitation was addressed to". So:
 *
 *   1. the attribute the tenant mapped to `email`, if they mapped one and the IdP sent it; otherwise
 *   2. the NameID — but ONLY when its Format is the emailAddress urn, because that urn is the IdP's own
 *      assertion that the value IS an email address.
 *
 * A persistent or transient NameID with no mapped email attribute resolves to nobody, and that is the
 * correct outcome rather than a bug: the settings screen says so in words (`name_id_format_is_email` is a
 * prop for exactly this), and guessing would mean provisioning an account keyed on an opaque identifier
 * that no invitation could ever match and no admin could recognise.
 *
 * ⚠️ THE MAPPED ATTRIBUTE WINS, BUT ITS ABSENCE IS NOT FATAL. An IdP legitimately omits an attribute for
 * some users (a service account, a freshly-created directory entry), and refusing the whole login would
 * make the tenant's own configuration the thing that broke it. Falling back to an emailAddress NameID is
 * not reaching for a DIFFERENT identity — the format urn is a claim about the same field.
 *
 * ── NOTHING HERE TRUSTS THE STRING'S SHAPE ──────────────────────────────────────────────────────────
 * The value is signed by the tenant's IdP, so it is authentic; authentic is not the same as well-formed.
 * `filter_var` refuses anything that is not an email, and the value is lower-cased because
 * `users.email` is matched by exact equality — an IdP that changes capitalisation between logins would
 * otherwise provision a second account for the same person on the second visit.
 */
final class SsoIdentityResolver
{
    /** `users.name` is `varchar(150)`; a directory display name can legitimately be longer. */
    private const MAX_NAME_LENGTH = 150;

    /**
     * @throws SsoAuthenticationException when the assertion carries no email this connection can use
     */
    public function resolve(SsoConnection $connection, SsoAssertion $assertion): SsoIdentity
    {
        $email = $this->email($connection, $assertion);

        if ($email === null) {
            throw SsoAuthenticationException::noEmail();
        }

        return new SsoIdentity($email, $this->name($connection, $assertion, $email));
    }

    private function email(SsoConnection $connection, SsoAssertion $assertion): ?string
    {
        $mapped = $this->mappedValue($connection, 'email', $assertion);

        if ($mapped !== null) {
            return $this->normaliseEmail($mapped);
        }

        // The Format the IdP actually stamped on the NameID, not the format the connection is CONFIGURED
        // for. The two can disagree — an admin re-points the picker without reconfiguring the IdP — and the
        // one that describes the value in front of us is the one that may be relied on.
        $format = $assertion->nameIdFormat ?? $connection->name_id_format;

        if ($format !== config('saml.default_name_id_format')) {
            return null;
        }

        return $this->normaliseEmail($assertion->nameId);
    }

    private function name(SsoConnection $connection, SsoAssertion $assertion, string $email): string
    {
        $direct = $this->mappedValue($connection, 'name', $assertion);

        if ($direct !== null) {
            return $this->trimToColumn($direct);
        }

        // Composed only when at least one half arrived: `trim()` on two nulls yields an empty string, and a
        // user whose name is '' renders as a blank row in every roster in the product.
        $composed = trim(implode(' ', array_filter([
            $this->mappedValue($connection, 'first_name', $assertion),
            $this->mappedValue($connection, 'last_name', $assertion),
        ], static fn (?string $part): bool => $part !== null && trim($part) !== '')));

        if ($composed !== '') {
            return $this->trimToColumn($composed);
        }

        // The local part, which is what `TenantMembershipService::resolveOrCreateUser()` uses for an
        // invited placeholder. Same fallback, so a member who arrives by invitation and one who arrives by
        // SSO are not distinguishable by the shape of their name.
        return $this->trimToColumn(Str::before($email, '@'));
    }

    /** The assertion attribute this tenant mapped to `$key`, or null if unmapped or unsent. */
    private function mappedValue(SsoConnection $connection, string $key, SsoAssertion $assertion): ?string
    {
        $attributeName = $connection->attribute_map[$key] ?? null;

        if (! is_string($attributeName) || trim($attributeName) === '') {
            return null;
        }

        $value = $assertion->attribute($attributeName);

        return $value === null || trim($value) === '' ? null : trim($value);
    }

    private function normaliseEmail(string $value): ?string
    {
        $email = Str::lower(trim($value));

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    private function trimToColumn(string $value): string
    {
        return Str::limit(trim($value), self::MAX_NAME_LENGTH, '');
    }
}
