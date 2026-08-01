<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Tenant;

/**
 * Absolute URLs on a tenant's own host, for links that leave the application (Increment H17).
 *
 * `route()` cannot be used: a queued job has no request, so the URL generator has no host to build
 * against and would emit the central domain — which is exactly the host a tenant-scoped link must
 * NOT use, since tenancy resolves by subdomain and `PreventAccessFromCentralDomains` rejects it.
 *
 * ── Two defects in the existing precedents that this deliberately does NOT inherit ──────────────
 * `TenantMembershipService::buildInviteAcceptUrl()` and `GuestDraftController::resumeUrl()` both do
 * `$tenant->domains()->value('domain') ?? $tenant->slug` and interpolate it straight into
 * `"https://{$domain}/…"`. Both halves of that are wrong:
 *
 *   1. THE HOST IS NOT A HOST. Under `InitializeTenancyBySubdomain` the `domains` table stores the
 *      SUBDOMAIN LABEL, not an FQDN — every seeder and test in the repo writes `'domain' => 'acme'`
 *      (`E2eSeeder.php:91`, `tests/Pest.php:272`). So those two builders emit `https://acme/…`,
 *      which resolves nowhere. The label has to be composed with the deployment's own host.
 *   2. THE SCHEME IS HARD-CODED. `https://` is wrong in local development, where the app serves on
 *      `http://acme.localhost:8080`.
 *
 * Both are fixed here by taking scheme, host AND port from `config('app.url')` and prefixing the
 * tenant's label — which yields `https://acme.meridian.test/…` in production and
 * `http://acme.localhost:8080/…` locally, with no branch on environment.
 *
 * The two older call sites are deliberately NOT retrofitted. They mint invitation and resume links
 * covered by their own tests, and changing live URL construction is a bigger change than H17 should
 * make in passing — but the defect is now written down and the helper exists for whoever fixes it.
 */
final class TenantUrl
{
    /** An absolute URL on the tenant's host. `$path` is appended verbatim and must be pre-encoded. */
    public static function to(Tenant $tenant, string $path): string
    {
        return self::scheme().'://'.self::host($tenant).'/'.ltrim($path, '/');
    }

    /**
     * The tenant's fully-qualified host.
     *
     * A stored value CONTAINING A DOT is treated as an already-complete custom domain and used
     * verbatim — that is the shape H22 will introduce, and silently gluing the central host onto
     * `forms.acme.com` would corrupt it. Anything else is a subdomain label to compose.
     */
    private static function host(Tenant $tenant): string
    {
        $stored = $tenant->domains()->value('domain');
        $label = is_string($stored) && $stored !== '' ? $stored : (string) $tenant->slug;

        if (str_contains($label, '.')) {
            return $label;
        }

        return $label.'.'.self::centralHost();
    }

    /**
     * Host (plus port, if any) of the deployment — `localhost:8080`, `meridian.test`.
     *
     * `app.url` rather than `tenancy.central_domain` because only `app.url` carries the port, and
     * a link to `acme.localhost` with the `:8080` dropped is as broken as no link at all.
     */
    private static function centralHost(): string
    {
        $url = (string) config('app.url');
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return (string) config('tenancy.central_domain');
        }

        $port = parse_url($url, PHP_URL_PORT);

        return is_int($port) ? $host.':'.$port : $host;
    }

    /**
     * The deployment's scheme, defaulting to https.
     *
     * Defaults CLOSED: an unparseable or missing `app.url` yields https, so a misconfiguration
     * downgrades nobody to cleartext.
     */
    private static function scheme(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'http' ? 'http' : 'https';
    }
}
