<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Tenancy\PlatformHost;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * What may name a workspace's platform subdomain (M95) — the single rule, so a later self-serve sign-up
 * reuses this one instead of writing a fifth copy of a host predicate.
 *
 * Nothing constrained it before. `tenants.slug` is a unique `varchar(255)` with no format check, and the
 * value becomes two things at once: the slug, and the `domains` row holding the bare LABEL that tenant
 * identification looks up. Each consumer has its own reason to need a DNS label:
 *
 *  - A DOT is refused by `domains_custom_requires_token_chk` (a dotted row is a custom domain and needs a
 *    verification token), and stancl's subdomain arm resolves only the FIRST label of a multi-level host
 *    while {@see PlatformHost::subdomainLabel()} treats that host as central — so a dotted slug would be
 *    unroutable in one path and misrouted in the other.
 *  - A label longer than 63 characters, or one that starts or ends with a hyphen, is not a valid DNS name,
 *    so no certificate or wildcard record could ever serve it.
 *  - UPPER CASE is refused rather than folded. stancl lower-cases `domains.domain` on save but not
 *    `tenants.slug`, and `TenantLocator` matches the slug exactly, so an unfolded `Pilot` would leave the two
 *    disagreeing. The caller lower-cases what the operator typed; this rule refuses anything it forgot.
 *
 * RESERVED: `www` only, because it is the one label a browser or a DNS provider supplies on its own. The
 * console and the API are paths on the central host, not subdomains, so no other label is claimed by the
 * application today. Widening the list later refuses only NEW workspaces; it never renames an existing one.
 */
final class SubdomainLabel implements ValidationRule
{
    /** 1 to 63 characters of lower-case letters, digits and interior hyphens. `D`: `$` never matches before a trailing newline. */
    public const string PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    /** @var list<string> */
    public const array RESERVED = ['www'];

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail(
                'The :attribute must be a single subdomain label: 1 to 63 lower-case letters, digits or hyphens, '
                .'starting and ending with a letter or digit, with no dots.'
            );

            return;
        }

        if (in_array($value, self::RESERVED, true)) {
            $fail("The :attribute '{$value}' is reserved and cannot name a workspace.");
        }
    }
}
