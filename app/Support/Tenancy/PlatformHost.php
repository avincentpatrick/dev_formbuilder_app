<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Http\Middleware\InitializeTenancyByPublicHost;
use App\Http\Middleware\RequirePlatformHost;
use App\Models\Domain;
use App\Models\Tenant;
use App\Rules\ClaimableDomain;

/**
 * Host → tenant, for code that runs OUTSIDE the tenancy middleware (Increment I5).
 *
 * The caller that forces this to exist is Fortify's `/register`: its middleware list is
 * `['web', RequirePlatformHost, AppSecurityHeaders]` and contains no tenancy middleware at all, so a POST
 * to `acme.meridian.test/register` has no resolved tenant and no RLS context — yet whether that request is
 * allowed, and what it should do afterwards, are both per-tenant questions.
 *
 * ── A FOURTH COPY OF THE SUBDOMAIN PREDICATE, DELIBERATELY NOT A REFACTOR ──────────────────────────────
 * The equality-plus-dot-boundary test below already exists three times: {@see RequirePlatformHost::isPlatformHost()},
 * {@see InitializeTenancyByPublicHost}'s `isSubdomain()`, and {@see ClaimableDomain}. Folding them into one
 * helper is the obvious tidy-up and is NOT done here: all three are I1/H22a-pinned files with asserted
 * routing matrices behind them (`CentralHostFallbackTest`, the H22a routing matrix), and a shared predicate
 * would put one refactor in the path of a phishing-surface guard, an identification rule and a
 * domain-claim rule at once. The duplication is recorded here rather than hidden so a later increment can
 * unify them ON PURPOSE, with those tests in view, rather than by accident.
 *
 * ── The `domains` lookup is safe from this angle ───────────────────────────────────────────────────────
 * `Domain` carries `ResolvableDomainScope`, which restricts resolution to dotless subdomain labels plus
 * LIVE custom domains — so a platform subdomain resolves normally here, and a custom host cannot, which is
 * exactly right: {@see RequirePlatformHost} has already 404ed the request before this runs.
 */
final class PlatformHost
{
    /** Whether the host is the central domain itself or one of its subdomains. */
    public static function isPlatformHost(string $host): bool
    {
        return self::centralFor($host) !== null;
    }

    /**
     * The subdomain label of a platform host, or null when the host IS the central domain (or is not a
     * platform host at all). `acme.meridian.test` → `acme`; `meridian.test` → null.
     */
    public static function subdomainLabel(string $host): ?string
    {
        $central = self::centralFor($host);

        if ($central === null || $host === $central) {
            return null;
        }

        $label = substr($host, 0, -1 * (strlen($central) + 1));

        // A multi-level host (`a.b.meridian.test`) is not a tenant subdomain — stancl's own identification
        // would not resolve it either, and returning `a.b` here would attempt a `domains` lookup for a
        // value that column never holds.
        return $label === '' || str_contains($label, '.') ? null : $label;
    }

    /** The tenant a platform host addresses, or null for the central domain / an unknown workspace. */
    public static function tenantFor(string $host): ?Tenant
    {
        $label = self::subdomainLabel($host);

        if ($label === null) {
            return null;
        }

        $domain = Domain::query()->where('domain', $label)->first();
        $tenant = $domain?->tenant;

        return $tenant instanceof Tenant ? $tenant : null;
    }

    private static function centralFor(string $host): ?string
    {
        /** @var array<int, string> $centralDomains */
        $centralDomains = (array) config('tenancy.central_domains', []);

        foreach ($centralDomains as $central) {
            if ($host === $central || str_ends_with($host, '.'.$central)) {
                return $central;
            }
        }

        return null;
    }
}
