<?php

declare(strict_types=1);

use App\Enums\ConnectorProviderKey;
use App\Models\Tenant;
use App\Services\Connectors\ConnectorRedirector;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The redirect URI's PORT (H16b).
|
| `tenancy.central_domain` is a bare hostname by construction — it feeds `Route::domain()`, which matches
| `Request::getHost()`, and that strips the port. So the OAuth URLs, which must match a provider console
| entry byte for byte, took their port from nowhere and had none. Locally that is a `redirect_uri_mismatch`
| before the consent screen renders; it also sent the post-consent browser to a portless tenant host that
| nothing serves.
|
| The port now comes from `app.url`, GUARDED on `app.url`'s host being the central domain. Every case below
| exists because getting one of them wrong is silent: a wrong port fails at the provider with a message the
| tenant sees and we never log, and a port grafted onto the wrong host would rewrite every asserted redirect
| in ConnectorOAuthFlowTest — which is how the first attempt at this fix was found and reverted.
*/

beforeEach(function (): void {
    TenantContext::flush();
    config()->set('connectors.tenant_url_scheme', 'http');
});

function callbackFor(string $centralDomain, ?string $appUrl): string
{
    config()->set('tenancy.central_domain', $centralDomain);
    config()->set('app.url', $appUrl);

    return app(ConnectorRedirector::class)->callbackUrl(ConnectorProviderKey::GoogleSheets);
}

it('carries the port app.url publishes the central host on', function (): void {
    // The local shape, and the reason this increment exists: the user registered
    // http://localhost:8080/oauth/google_sheets/callback and the app emitted it without the port.
    expect(callbackFor('localhost', 'http://localhost:8080'))
        ->toBe('http://localhost:8080/oauth/google_sheets/callback');
});

it('emits no port when app.url has none — the production shape is unchanged', function (): void {
    config()->set('connectors.tenant_url_scheme', 'https');

    expect(callbackFor('meridian.io', 'https://meridian.io'))
        ->toBe('https://meridian.io/oauth/google_sheets/callback');
});

it('refuses to graft a port from a DIFFERENT host, which is the whole safety of the derivation', function (): void {
    // A port is a property of an origin, not a free-floating number. `app.url` naming another host means
    // its port describes something else entirely. This is also precisely the suite's own configuration
    // before APP_URL was pinned in phpunit.xml — CENTRAL_DOMAIN=meridian.test against a localhost:8080
    // app.url — so without this guard, ten asserted redirects in ConnectorOAuthFlowTest would have moved.
    expect(callbackFor('meridian.test', 'http://localhost:8080'))
        ->toBe('http://meridian.test/oauth/google_sheets/callback');
});

it('drops a port its own scheme already implies, because providers compare strings not URLs', function (): void {
    config()->set('connectors.tenant_url_scheme', 'https');

    // `https://meridian.io:443` and `https://meridian.io` are the same URL after RFC 3986 normalisation
    // and two different strings to every OAuth provider's console.
    expect(callbackFor('meridian.io', 'https://meridian.io:443'))
        ->toBe('https://meridian.io/oauth/google_sheets/callback');

    config()->set('connectors.tenant_url_scheme', 'http');

    expect(callbackFor('meridian.io', 'http://meridian.io:80'))
        ->toBe('http://meridian.io/oauth/google_sheets/callback');
});

it('takes only the PORT from app.url — the scheme stays CONNECTOR_TENANT_URL_SCHEME', function (): void {
    // Caught by getting the case above wrong on the first run, and worth pinning rather than fixing
    // quietly: it would be natural to assume a URL derived from `app.url` inherits its scheme too.
    // It does not, and that is deliberate — `connectors.tenant_url_scheme` already exists precisely so a
    // deployment can serve https while `app.url` says otherwise, and H16b widened the port only.
    // Collapsing the two knobs would look like tidying and would silently downgrade a live redirect URI.
    config()->set('connectors.tenant_url_scheme', 'https');

    expect(callbackFor('localhost', 'http://localhost:8080'))
        ->toBe('https://localhost:8080/oauth/google_sheets/callback');
});

it('degrades to the bare host rather than throwing on an absent or unparseable app.url', function (): void {
    // An unset APP_URL must not take the OAuth surface down with it — the pre-H16b behaviour is the
    // correct fallback here, because a host with no port is exactly what this used to emit always.
    expect(callbackFor('meridian.test', ''))
        ->toBe('http://meridian.test/oauth/google_sheets/callback')
        ->and(callbackFor('meridian.test', null))
        ->toBe('http://meridian.test/oauth/google_sheets/callback')
        ->and(callbackFor('meridian.test', ':::not a url:::'))
        ->toBe('http://meridian.test/oauth/google_sheets/callback');
});

it('carries the port into the post-consent landing too — the second victim of the same bug', function (): void {
    // `tenantHost()` composes the tenant's subdomain against the SAME central host, so before this fix a
    // flow that did complete returned the browser to http://acme.localhost/integrations. Nothing in the
    // OAuth suite covered it, because every one of its cases pins a central domain with no port at all.
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);

    config()->set('tenancy.central_domain', 'localhost');
    config()->set('app.url', 'http://localhost:8080');

    $redirector = app(ConnectorRedirector::class);

    expect($redirector->tenantHost($tenant->id))->toBe('acme.localhost:8080')
        ->and($redirector->successUrl($tenant->id, ConnectorProviderKey::GoogleSheets))
        ->toBe('http://acme.localhost:8080/integrations?connected=google_sheets');
});

it('pins the suite-wide invariant that APP_URL and CENTRAL_DOMAIN agree', function (): void {
    // phpunit.xml forces both. A future edit that pins one and not the other reintroduces the
    // self-contradicting test environment this increment closed, and nothing else would notice: the
    // host guard above means the redirects stay green either way.
    expect(parse_url((string) config('app.url'), PHP_URL_HOST))
        ->toBe(config('tenancy.central_domain'))
        ->and(parse_url((string) config('app.url'), PHP_URL_PORT))->toBeNull();
});
