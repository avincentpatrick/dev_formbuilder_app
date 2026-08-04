<?php

declare(strict_types=1);

use App\Models\User;
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

/*
| ── The custom-domain state machine, at the routing layer ────────────────────────────────────────────
*/

it('serves the guest runtime on a live custom domain', function (): void {
    // The feature, end to end at this layer: identification succeeded on a host that is not a subdomain
    // of any central domain, and the request reached the controller under that tenant's RLS context.
    customDomain(inboxTenant('acme'), 'forms.acme-example.com');

    $this->withoutVite()->get('http://forms.acme-example.com/f/no-such-form')->assertNotFound();
});

it('refuses the guest runtime on a custom domain that is not live yet', function (bool $verified, bool $activated): void {
    // `pending` is the obvious case. `verified` is the one that matters: control of the hostname has
    // been proven, but no certificate exists for it until an operator installs one, so the row must
    // still route nowhere. That is the whole content of the fail-closed TLS posture.
    customDomain(inboxTenant('acme'), 'forms.acme-example.com', $verified, $activated);

    $this->withoutVite()->get('http://forms.acme-example.com/f/no-such-form')
        ->assertRedirect(config('app.url'));
})->with([
    'pending' => [false, false],
    'verified but not activated' => [true, false],
]);

it('keeps serving the subdomain while a custom domain is live', function (): void {
    // Two rows for one tenant. The label row is not superseded — it stays the tenant's app host, and
    // every authenticated surface still lives there.
    customDomain(inboxTenant('acme'), 'forms.acme-example.com');

    $this->withoutVite()->get('http://acme.meridian.test/f/no-such-form')->assertNotFound();
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

    $this->actingAs(User::factory()->create())->withoutVite()
        ->get("http://forms.acme-example.com{$path}")
        ->assertRedirect(config('app.url'));
})->with(['/dashboard', '/settings']);

it('keeps the no-auth invitation group off the public-host identifier', function (): void {
    // No `auth` on this group, so it reaches identification directly and fails closed there.
    inboxTenant('acme');

    $this->withoutVite()->get('http://forms.acme-example.com/invitations/whatever')
        ->assertRedirect(config('app.url'));
});

/*
| ── RequirePlatformHost: the platform's own surfaces are not the tenant's to serve ───────────────────
*/

it('404s the platform auth surface and landing page on a custom domain', function (string $path): void {
    // The gap the block above surfaced. `auth` is priority-ordered AHEAD of the tenancy pipeline, so an
    // unauthenticated request to a tenant route never reaches identification — it redirects to /login on
    // whatever host it arrived at. Fortify registers /login, /register and /forgot-password with
    // 'domain' => null and routes/web.php serves '/' host-agnostically, so without RequirePlatformHost
    // the platform's CREDENTIAL FORM would render on a hostname the tenant controls.
    customDomain(inboxTenant('acme'), 'forms.acme-example.com');

    $this->withoutVite()->get("http://forms.acme-example.com{$path}")->assertNotFound();
})->with(['/login', '/forgot-password', '/']);

it('still serves the platform auth surface on the central host and its subdomains', function (string $host): void {
    // The predicate allows the central host AND its subdomains, which is not incidental: tenant users log
    // in at acme.meridian.test/login, so refusing anything but the apex would lock every tenant out.
    inboxTenant('acme');

    $this->withoutVite()->get("http://{$host}/login")->assertOk();
})->with(['meridian.test', 'acme.meridian.test', 'localhost', 'acme.localhost']);
