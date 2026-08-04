<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Rules\ClaimableDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;

/**
 * Tenant identification for the PUBLIC GUEST RUNTIME only (H22a / ADR-0012) — the one route group a
 * tenant's own custom domain may serve. Everything else (the authenticated app, invitations, both
 * /api/v1 groups) keeps {@see InitializeTenancyBySubdomain}, per ADR-0009
 * §D2: "Business-tier `custom_domain` covers public forms, not the admin app". A custom host on those
 * groups therefore throws NotASubdomainException, which bootstrap/app.php already renders as a
 * redirect to the central app — fail-closed with no new code.
 *
 * This subclass exists for ONE reason: stancl's `isSubdomain()` is a bare
 * `Str::endsWith($host, config('tenancy.central_domains'))`, and that predicate is wrong in two
 * independent ways that both become reachable the moment the domain arm is enabled.
 *
 *   1. NO DOT BOUNDARY. `evilmeridian.test` "ends with" `meridian.test`, so it would take the
 *      SUBDOMAIN arm and resolve the tenant whose label is `evilmeridian`. An attacker who owns that
 *      real domain and points it at us would be served that tenant's forms on their own hostname.
 *      Here a suffix match requires the leading dot, so it falls to the full-host lookup, finds no
 *      row, and fails closed.
 *
 *   2. A BARE-LABEL HOST. `Host: acme` ends with none of the central domains, so it would take the
 *      DOMAIN arm — and the full-host lookup would match on the literal string `acme`, which is
 *      exactly what a subdomain tenant stores in `domains.domain`. Every tenant's label would become
 *      reachable as a bare Host header from any network position (TrustHosts is not enabled, so
 *      getHost() is the raw header). Routing dotless hosts to the subdomain arm restores stancl's
 *      own `count($parts) === 1` rejection instead of inventing a second one.
 *
 * The predicate is deliberately the SAME one {@see ClaimableDomain} refuses a claim with,
 * so what routes and what may be claimed cannot drift apart.
 *
 * Every host the suite exercises behaves byte-identically to InitializeTenancyBySubdomain:
 *
 *   acme.meridian.test  → subdomain arm → resolve label `acme`
 *   acme.localhost      → subdomain arm → resolve label `acme`   (the e2e host; getHost() drops :8080)
 *   meridian.test       → subdomain arm → NotASubdomainException (apex; $isACentralDomain)
 *   localhost           → subdomain arm → NotASubdomainException ($isLocalhost)
 *   127.0.0.1           → subdomain arm → NotASubdomainException ($isIpAddress; it is a central domain)
 *   ghost.localhost     → subdomain arm → TenantCouldNotBeIdentifiedOnDomainException
 *   acme                → subdomain arm → NotASubdomainException ($isLocalhost)
 *   evilmeridian.test   → domain arm    → no row → TenantCouldNotBeIdentifiedOnDomainException
 *   forms.acme.com      → domain arm    → the live custom-domain row, or no row → fails closed
 *
 * `handle()` is inherited unchanged: it resolves the two arms out of the container rather than
 * running them as pipeline entries, so middleware priority does not apply to them — only to this
 * class, which bootstrap/app.php lists explicitly (see the note there on the provider's prepend).
 */
final class InitializeTenancyByPublicHost extends InitializeTenancyByDomainOrSubdomain
{
    protected function isSubdomain(string $hostname): bool
    {
        // A dotless host can never be a custom domain, and must not reach the full-host lookup.
        if (! str_contains($hostname, '.')) {
            return true;
        }

        /** @var array<int, string> $centralDomains */
        $centralDomains = (array) config('tenancy.central_domains', []);

        foreach ($centralDomains as $central) {
            if ($hostname === $central || str_ends_with($hostname, '.'.$central)) {
                return true;
            }
        }

        return false;
    }
}
