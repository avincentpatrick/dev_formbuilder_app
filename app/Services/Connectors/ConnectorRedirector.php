<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\ConnectorProviderKey;
use App\Models\Tenant;

/**
 * Builds the two absolute URLs the OAuth flow crosses hosts on (ADR-0009 §D2/§D5, H15a) — kept in one class
 * because both are security-relevant and neither should ever be assembled ad hoc at a call site.
 *
 * {@see callbackUrl()} is the provider's registered redirect URI: always the CENTRAL host, read from
 * `config('tenancy.central_domain')` rather than `env()` so it survives `route:cache` (the routes/admin.php
 * precedent), and it must match the value registered in the provider's app console byte for byte.
 *
 * {@see returnUrl()} is where the browser lands afterwards, and it is the open-redirect control: the host is
 * looked up from the tenant's own `domains` row and NEVER echoed from the request or from a claim in the
 * state payload, so even a validly-signed state cannot steer the browser anywhere but the tenant's own app.
 * Only the path is configurable. The outcome rides a query parameter because the central host cannot write
 * the tenant host's session cookie, so an Inertia flash is not available across this boundary.
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
     * `domains.domain` holds the SUBDOMAIN LABEL that stancl/tenancy identifies on (`acme`), not a full host,
     * so a label is expanded against the central domain here. A stored value that already contains a dot is
     * treated as a complete host, so a future custom-domain tenant needs no change. (The invite and resume
     * URL builders predate this and use the bare label directly — a defect in those, not a convention this
     * increment should copy.)
     */
    public function tenantHost(string $tenantId): ?string
    {
        $tenant = Tenant::query()->whereKey($tenantId)->first();

        if ($tenant === null) {
            return null;
        }

        $domain = $tenant->domains()->value('domain');
        $label = is_string($domain) && $domain !== '' ? $domain : $tenant->slug;

        return str_contains($label, '.') ? $label : $label.'.'.$this->centralHost();
    }

    private function centralHost(): string
    {
        return (string) config('tenancy.central_domain');
    }

    private function scheme(): string
    {
        $scheme = config('connectors.tenant_url_scheme', 'https');

        return is_string($scheme) && $scheme !== '' ? $scheme : 'https';
    }
}
