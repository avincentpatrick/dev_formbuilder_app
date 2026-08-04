<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| H22a — the host matrix for the ONE route group a custom domain may serve: the public guest runtime
| (routes/tenant.php). Identification there is App\Http\Middleware\InitializeTenancyByPublicHost.
|
| Reading the assertions: `/f/{slug}` returns 404 for an unknown slug, so a 404 means IDENTIFICATION
| SUCCEEDED and the request reached the controller under that tenant's RLS context. A redirect to
| config('app.url') means identification FAILED — bootstrap/app.php renders both
| NotASubdomainException and TenantCouldNotBeIdentifiedOnDomainException that way, deliberately, so a
| probe cannot tell "no such tenant" from "not a tenant host".
|
| The first block is a REGRESSION matrix: every host the suite and the e2e stack already use must
| behave exactly as it did under InitializeTenancyBySubdomain. The second block covers the two holes a
| bare swap to stancl's InitializeTenancyByDomainOrSubdomain would have opened — both of them
| exploitable, neither of them caught by any existing test.
*/

it('resolves a tenant on its platform subdomain', function (): void {
    inboxTenant('acme');

    $this->withoutVite()->get('http://acme.meridian.test/f/no-such-form')->assertNotFound();
});

it('redirects an unknown subdomain to the central app', function (): void {
    $this->withoutVite()->get('http://ghost.meridian.test/f/no-such-form')
        ->assertRedirect(config('app.url'));
});

it('redirects the central apex to the central app', function (): void {
    // Reaches the subdomain arm and trips stancl's $isACentralDomain. The apex is where
    // routes/admin.php lives (Route::domain()), so it must never resolve a tenant.
    $this->withoutVite()->get('http://meridian.test/f/no-such-form')
        ->assertRedirect(config('app.url'));
});

it('redirects the loopback hosts to the central app', function (string $host): void {
    // Both are `tenancy.central_domains` entries. `localhost` trips $isLocalhost, `127.0.0.1` trips
    // $isIpAddress — and 127.0.0.1 only reaches the subdomain arm at all because isSubdomain() tests
    // the full central_domains LIST, not just the singular central_domain.
    $this->withoutVite()->get("http://{$host}/f/no-such-form")
        ->assertRedirect(config('app.url'));
})->with(['localhost', '127.0.0.1']);

it('still resolves a tenant on the e2e localhost subdomain', function (): void {
    // Playwright drives acme.localhost:8080. getHost() drops the port; `localhost` is a central domain,
    // so this takes the subdomain arm exactly as it does today.
    inboxTenant('acme');

    $this->withoutVite()->get('http://acme.localhost/f/no-such-form')->assertNotFound();
});

/*
| ── The two holes a bare stancl swap would open ──────────────────────────────────────────────────────
*/

it('refuses a bare-label host that matches a tenant subdomain row', function (): void {
    // `Host: acme` ends with no central domain, so stancl's isSubdomain() would send it to the DOMAIN
    // arm — where the full-host lookup matches on the literal string `acme`, which is exactly what a
    // subdomain tenant stores in domains.domain. Every tenant's label would become reachable as a bare
    // Host header from any network position; TrustHosts is not enabled, so getHost() is the raw header.
    inboxTenant('acme');

    $this->withoutVite()->get('http://acme/f/no-such-form')
        ->assertRedirect(config('app.url'));
});

it('refuses a third-party host that merely shares a suffix with a central domain', function (): void {
    // `evilmeridian.test` "ends with" `meridian.test` under a bare Str::endsWith, so stancl would send
    // it to the SUBDOMAIN arm and resolve the tenant labelled `evilmeridian`. Someone who owns that
    // real domain and points it at us would be served that tenant's forms on their own hostname.
    // Requiring the leading dot sends it to the full-host lookup instead, where it finds no row.
    inboxTenant('evilmeridian');

    $this->withoutVite()->get('http://evilmeridian.test/f/no-such-form')
        ->assertRedirect(config('app.url'));
});

it('refuses an unregistered third-party host', function (): void {
    // The domain arm's default: no `domains` row for this host, so it fails closed. This is also the
    // state every custom domain is in before it is claimed, verified and activated.
    inboxTenant('acme');

    $this->withoutVite()->get('http://forms.acme-example.com/f/no-such-form')
        ->assertRedirect(config('app.url'));
});

it('keeps the authenticated app off the public-host identifier', function (string $path): void {
    // The ADR-0009 §D2 scoping, pinned: only the guest group was swapped. This group still carries
    // InitializeTenancyBySubdomain, so a third-party host throws NotASubdomainException there with no
    // new code — which is what removes the cross-host session/CSRF/bearer-token surface entirely.
    //
    // actingAs() is required, and the reason is the CentralHostFallbackTest premise: `auth` is
    // priority-ordered BEFORE the tenancy pipeline, so an UNAUTHENTICATED request never reaches
    // identification — it redirects to /login on whatever host it arrived at. That is a real finding,
    // not a test artefact: Fortify registers /login with no domain constraint, so the platform's
    // credential form would render on a tenant-controlled hostname. RequirePlatformHost closes it, and
    // `it 404s the platform auth surface on a custom host` below is the assertion for that.
    inboxTenant('acme');

    $this->actingAs(App\Models\User::factory()->create())->withoutVite()
        ->get("http://forms.acme-example.com{$path}")
        ->assertRedirect(config('app.url'));
})->with(['/dashboard', '/settings']);

it('keeps the no-auth invitation group off the public-host identifier', function (): void {
    // No `auth` on this group, so it reaches identification directly and fails closed there.
    inboxTenant('acme');

    $this->withoutVite()->get('http://forms.acme-example.com/invitations/whatever')
        ->assertRedirect(config('app.url'));
});
