<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\ConnectorProviderKey;
use App\Http\Middleware\RequirePlatformHost;
use App\Models\Domain;
use App\Models\Tenant;
use App\Support\Tenancy\TenantUrl;

/**
 * Builds the two absolute URLs the OAuth flow crosses hosts on (ADR-0009 §D2/§D5, H15a) — kept in one class
 * because both are security-relevant and neither should ever be assembled ad hoc at a call site.
 *
 * {@see callbackUrl()} is the provider's registered redirect URI: always the CENTRAL host, read from
 * `config('tenancy.central_domain')` rather than `env()` so it survives `route:cache` (the routes/admin.php
 * precedent), and it must match the value registered in the provider's app console byte for byte. **Byte
 * for byte includes the PORT**, which that config value structurally cannot hold — see {@see centralHost()}.
 *
 * {@see returnUrl()} is where the browser lands afterwards, and it is the open-redirect control: the host is
 * looked up from the tenant's own `domains` row and NEVER echoed from the request or from a claim in the
 * state payload, so even a validly-signed state cannot steer the browser anywhere but the tenant's own app.
 * Only the path is configurable. The outcome rides a query parameter because the central host cannot write
 * the tenant host's session cookie, so an Inertia flash is not available across this boundary.
 *
 * H22a made that claim structurally true rather than merely intended. Once a tenant can write its own
 * hostname into `domains`, "looked up from the tenant's own row" would otherwise have become the attack
 * rather than the control. Two things close it: {@see tenantHost()} reads only the DOTLESS row, and
 * {@see Domain}'s global scope hides any custom domain that is not live from every query in the
 * application. A tenant cannot steer this redirect even by claiming a domain it does control.
 */
final class ConnectorRedirector
{
    public function callbackUrl(ConnectorProviderKey $provider): string
    {
        return $this->scheme().'://'.$this->centralHost().'/oauth/'.$provider->value.'/callback';
    }

    /** The success landing: the tenant's own integrations page, told which provider just connected. */
    public function successUrl(string $tenantId, ConnectorProviderKey $provider): string
    {
        return $this->returnUrl($tenantId, ['connected' => $provider->value]);
    }

    /**
     * The failure landing. `$errorCode` is the provider's own code (or `access_denied` when the user declined)
     * — a short machine token, never a message or a response body, so nothing sensitive rides the URL.
     */
    public function failureUrl(string $tenantId, ConnectorProviderKey $provider, string $errorCode): string
    {
        return $this->returnUrl($tenantId, [
            'provider' => $provider->value,
            'connect_error' => mb_substr($errorCode, 0, 64),
        ]);
    }

    /**
     * The fallback when there is no tenant to return to — an unverifiable state, so we cannot know whose app
     * to land on and must not guess. Deliberately discloses nothing about whether the named tenant exists.
     */
    public function fallbackUrl(): string
    {
        return (string) config('app.url');
    }

    /**
     * @param  array<string, string>  $query
     */
    private function returnUrl(string $tenantId, array $query): string
    {
        $host = $this->tenantHost($tenantId);

        if ($host === null) {
            return $this->fallbackUrl();
        }

        $path = (string) config('connectors.return_path', '/integrations');

        return $this->scheme().'://'.$host.$path.'?'.http_build_query($query);
    }

    /**
     * The tenant's absolute host. `tenants`/`domains` are RLS-exempt central tables, so this resolves under
     * any context (or none) — which matters, since the callback runs on the central domain.
     *
     * `domains.domain` holds the SUBDOMAIN LABEL that tenancy identifies on (`acme`), not a full host, so
     * the label is expanded against the central domain here.
     *
     * H22a: this reads the DOTLESS row explicitly rather than whichever row came back first. Two reasons,
     * both load-bearing. (1) The landing page is `/integrations` — the AUTHENTICATED app, which ADR-0009
     * §D2 keeps off custom domains, so returning a custom host here would 302 the user to the central app
     * at the end of a successful OAuth flow. (2) `value('domain')` is `first()` with no ORDER BY, so once a
     * tenant has two rows the answer depended on physical row order.
     *
     * This deliberately does NOT delegate to {@see TenantUrl}: that class composes the whole URL from
     * `app.url`, while the OAuth URLs must take their HOST from `tenancy.central_domain` to match what is
     * registered in the provider's console. ⚠️ AMENDED IN H16b: the two classes still differ on the host,
     * but they no longer differ on the PORT — see {@see centralHost()}.
     */
    public function tenantHost(string $tenantId): ?string
    {
        $tenant = Tenant::query()->whereKey($tenantId)->first();

        if ($tenant === null) {
            return null;
        }

        $domain = $tenant->domains()->whereRaw("position('.' in domain) = 0")->orderBy('domain')->value('domain');
        $label = is_string($domain) && $domain !== '' ? $domain : $tenant->slug;

        return $label.'.'.$this->centralHost();
    }

    /**
     * The central host, carrying the port `app.url` publishes it on — `meridian.io` in production,
     * `localhost:8080` locally (H16b).
     *
     * ── THE BUG THIS CLOSES, AND IT HAD TWO VICTIMS RATHER THAN ONE ─────────────────────────────────
     * `tenancy.central_domain` is a bare HOSTNAME by construction: it feeds `Route::domain()`, which
     * matches `Request::getHost()`, and that STRIPS the port. So the value cannot carry one. Returning it
     * unmodified emitted `http://localhost/oauth/google_sheets/callback` while the Google console has
     * `http://localhost:8080/...` — a `redirect_uri_mismatch` before the consent screen even renders. The
     * second victim is quieter and was never written down: {@see tenantHost()} builds the POST-consent
     * landing from this same method, so a flow that did somehow complete returned the browser to
     * `http://demo.localhost/integrations`, a URL nothing serves.
     *
     * ⚠️ THE OBVIOUS FIX IS THE TRAP. Putting the port in `CENTRAL_DOMAIN` looks equivalent and is not:
     * that env var feeds BOTH `tenancy.central_domain` and the `tenancy.central_domains` ARRAY
     * (`config/tenancy.php:38` and `:30`). Because `Route::domain()` compares against a port-less host,
     * `localhost:8080` there silently deletes the super-admin console route, THIS CALLBACK'S OWN ROUTE,
     * {@see RequirePlatformHost} and subdomain tenant identification — four
     * failures, none of which name a port.
     *
     * ── WHY THE PORT IS TAKEN FROM `app.url` AND WHY THE HOST MUST MATCH ────────────────────────────
     * `app.url` is the only place that records which port the central app is actually published on
     * (`TenantUrl` already composes every tenant link from it). The host guard in {@see centralPort()} is
     * what keeps that safe: a port belongs to the central host only when `app.url` IS the central host.
     * Without it, a test environment that pins `CENTRAL_DOMAIN=meridian.test` while `app.url` still reads
     * `http://localhost:8080` would graft `:8080` onto `meridian.test` and rewrite every asserted
     * redirect in the flow — which is exactly how the first attempt at this fix reddened ten of them.
     */
    private function centralHost(): string
    {
        $host = (string) config('tenancy.central_domain');
        $port = $this->centralPort($host);

        return $port === null ? $host : $host.':'.$port;
    }

    /**
     * The port `app.url` publishes `$centralHost` on, or null when there is none to add.
     *
     * Null in four distinct cases, and all four are correct: `app.url` names a DIFFERENT host (so its
     * port describes something else), `app.url` carries no explicit port (production over 443/80, where
     * the registered redirect URI has none either), `app.url` states the port its own scheme already
     * implies, or `app.url` is absent/unparseable.
     *
     * The third case is the only non-obvious one. `parse_url` reports a port only when it is written out,
     * so `https://meridian.io:443` — a legitimate thing for an ops template to emit — would otherwise
     * produce a redirect URI of `https://meridian.io:443/oauth/...` against a console entry of
     * `https://meridian.io/oauth/...`. Same URL by RFC 3986 normalisation, and a `redirect_uri_mismatch`
     * to every provider, because they compare the string.
     */
    private function centralPort(string $centralHost): ?int
    {
        $parts = parse_url((string) config('app.url'));

        if (! is_array($parts) || ($parts['host'] ?? null) !== $centralHost) {
            return null;
        }

        $port = $parts['port'] ?? null;

        if (! is_int($port)) {
            return null;
        }

        $implied = match ($parts['scheme'] ?? null) {
            'https' => 443,
            'http' => 80,
            default => null,
        };

        return $port === $implied ? null : $port;
    }

    private function scheme(): string
    {
        $scheme = config('connectors.tenant_url_scheme', 'https');

        return is_string($scheme) && $scheme !== '' ? $scheme : 'https';
    }
}
