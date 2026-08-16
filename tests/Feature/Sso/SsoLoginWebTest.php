<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\SsoAuthIntent;
use App\Models\SsoAuthRequest;
use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sso\SsoAuthnRequestBuilder;
use App\Support\Sso\SsoSession;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| GET /sso/saml/login (P1b, ADR-0016 §D9) — the SP-initiated entry point, and the ONLY door into the flow.
| `allow_unsolicited` is a permanent false, so every assertion the ACS will ever accept traces back to a row
| this endpoint wrote.
|
| ⚠️ THE 404 ARMS ARE THE POINT OF §D4, NOT PADDING. Unentitled, unconfigured, draft and disabled must be
| INDISTINGUISHABLE to an anonymous caller who supplied nothing but a hostname — any other answer discloses
| another organisation's plan. Each arm is asserted separately because they fail for four different reasons
| inside SsoGate and a regression in any one of them would still leave the other three green.
|
| ⚠️ Enterprise is the only tier carrying `sso_saml`, and `SsoGate::activeConnectionOrAbort()` fails CLOSED
| on a null plan — so a case that skipped assignPlanTier() would 404 for the wrong reason and prove nothing.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    assignPlanTier(PlanTier::Enterprise);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function loginUrl(string $slug = 'acme'): string
{
    return "http://{$slug}.meridian.test/sso/saml/login";
}

/** Re-enter the tenant and hand back the single minted request — the GUC teardown guard, in one place. */
function mintedRequest(Tenant $tenant, User $actor): ?SsoAuthRequest
{
    enterTenant($tenant->id, $actor->id);

    return SsoAuthRequest::query()->first();
}

/** The `<samlp:AuthnRequest>` the redirect actually carries, decoded through the builder's own transport. */
function sentAuthnRequest(string $location): DOMXPath
{
    $query = [];
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query)->toHaveKey('SAMLRequest');

    $document = new DOMDocument;
    expect($document->loadXML(SsoAuthnRequestBuilder::decodeTransport((string) $query['SAMLRequest'])))->toBeTrue();

    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
    $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');

    return $xpath;
}

/*
|--------------------------------------------------------------------------
| The four ways in that are not ways in
|--------------------------------------------------------------------------
*/

it('is not there for a tenant with no connection at all', function (): void {
    $this->get(loginUrl())->assertNotFound();
});

it('is not there while the connection is still a draft', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::factory()->create();

    $this->get(loginUrl())->assertNotFound();
});

it('is not there once an admin has disabled the connection', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::factory()->disabled()->create();

    $this->get(loginUrl())->assertNotFound();
});

it('is not there for a tenant whose plan does not carry sso_saml, even with an active connection', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::factory()->active()->create();
    assignPlanTier(PlanTier::Business);

    $this->get(loginUrl())->assertNotFound();
});

it('is not there on a tenant that has no connection, while its neighbour has an active one', function (): void {
    // The isolation this endpoint rests on is the DATABASE's, not a controller filter: the connection row
    // is strict FORCE RLS and the tenant comes from the HOST. An attacker choosing which workspace to
    // address chooses only which public subdomain to visit.
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::factory()->active()->create();

    $neighbour = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'default_locale' => 'en']);
    $neighbour->domains()->create(['domain' => 'beta']);

    $this->get(loginUrl('beta'))->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The round trip's outbound leg
|--------------------------------------------------------------------------
*/

it('redirects to the identity provider and remembers that it did', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    $connection = SsoConnection::factory()->active()->create();

    $response = $this->get(loginUrl())->assertRedirect();
    $location = (string) $response->headers->get('Location');

    expect($location)->toStartWith($connection->idp_sso_url.'?SAMLRequest=');

    $request = mintedRequest($this->tenant, $this->admin);

    expect($request)->not->toBeNull()
        ->and($request->intent)->toBe(SsoAuthIntent::Login)
        // Null, and the CHECK constraint agrees: a login row carrying a subject would let a consumed login
        // assertion masquerade as a step-up.
        ->and($request->user_id)->toBeNull()
        ->and($request->force_authn)->toBeFalse()
        ->and($request->consumed_at)->toBeNull()
        // Chosen by the SP, never echoed through RelayState — and P1b has no server-side origin to record,
        // so accepting one from the query string would be an open redirect wearing a column name.
        ->and($request->return_to)->toBeNull()
        ->and($request->sso_connection_id)->toBe((string) $connection->getKey())
        ->and($request->ip_address)->not->toBeNull()
        // P1e's three, all null until an assertion comes back: the ACS writes the first two together
        // because a CHECK refuses a verified login row with no subject, and the hop writes the third.
        ->and($request->verified_at)->toBeNull()
        ->and($request->resolved_user_id)->toBeNull()
        ->and($request->completed_at)->toBeNull();

    // `xs:ID` derives from `xs:NCName`, which may not begin with a digit — a bare hex string does so half
    // the time, and some IdPs echo back a mangled InResponseTo that then fails to match.
    expect($request->request_id)->toHaveLength(33)->toStartWith('_');

    // Cast: Carbon 3 returns a float here, and `toBe` is identity — 600.0 is not 600.
    expect((int) $request->expires_at->diffInSeconds($request->issued_at, absolute: true))
        ->toBe((int) config('saml.authn_request_ttl_seconds'));
});

it('sends a request the identity provider can act on, addressed back to the canonical subdomain', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    $connection = SsoConnection::factory()->active()->create();

    $response = $this->get(loginUrl())->assertRedirect();
    $xpath = sentAuthnRequest((string) $response->headers->get('Location'));

    $request = mintedRequest($this->tenant, $this->admin);
    $root = $xpath->query('/samlp:AuthnRequest')?->item(0);

    expect($root)->toBeInstanceOf(DOMElement::class);

    // The SP's memory and the document it sent must name the same id, or the InResponseTo check on the way
    // back has nothing to match.
    expect($root->getAttribute('ID'))->toBe($request?->request_id)
        ->and($root->getAttribute('Version'))->toBe('2.0')
        ->and($root->getAttribute('Destination'))->toBe($connection->idp_sso_url)
        ->and($root->getAttribute('ProtocolBinding'))->toBe(config('saml.acs_binding'))
        // ⚠️ THE CANONICAL SUBDOMAIN, NEVER A CUSTOM DOMAIN (§D3). This is the same string the returning
        // assertion's Destination and Recipient are checked against, which is why both come from
        // SsoMetadataController rather than being derived twice.
        ->and($root->getAttribute('AssertionConsumerServiceURL'))->toBe('http://acme.meridian.test/sso/saml/acs')
        // Absent on a plain login: an IdP answering from an existing session is what single sign-on IS.
        ->and($root->hasAttribute('ForceAuthn'))->toBeFalse();

    expect($xpath->query('/samlp:AuthnRequest/saml:Issuer')?->item(0)?->textContent)
        ->toBe('http://acme.meridian.test/sso/saml/metadata');

    $policy = $xpath->query('/samlp:AuthnRequest/samlp:NameIDPolicy')?->item(0);

    expect($policy)->toBeInstanceOf(DOMElement::class)
        ->and($policy->getAttribute('Format'))->toBe($connection->name_id_format)
        // A JIT-provisioning SP is by definition willing for the IdP to establish an identifier for a
        // subject it has not sent us before; without this some IdPs refuse the first-time login JIT exists
        // to serve.
        ->and($policy->getAttribute('AllowCreate'))->toBe('true');
});

it('asks the identity provider for no particular authentication context', function (): void {
    // php-saml's own builder defaults `requestedAuthnContext` to TRUE, emitting PasswordProtectedTransport
    // with Comparison="exact" — which refuses a successful passwordless or certificate-based login. This SP
    // has no policy about HOW the IdP authenticated. The assertion is here because the absence is a
    // decision, and a switch back to the library's builder would silently reverse it.
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::factory()->active()->create();

    $response = $this->get(loginUrl())->assertRedirect();
    $xpath = sentAuthnRequest((string) $response->headers->get('Location'));

    expect($xpath->query('//samlp:RequestedAuthnContext')?->length)->toBe(0);
});

it('appends its parameter to an identity provider URL that already carries a query string', function (): void {
    // ADFS instance ids and Okta app parameters are both real; assuming a bare URL drops the request on the
    // floor at the IdP, which reports nothing useful back.
    enterTenant($this->tenant->id, $this->admin->id);
    $connection = SsoConnection::factory()->active()->create();
    $connection->forceFill(['idp_sso_url' => 'https://idp.example.com/sso?instance=7'])->save();

    $response = $this->get(loginUrl())->assertRedirect();
    $location = (string) $response->headers->get('Location');

    expect($location)->toStartWith('https://idp.example.com/sso?instance=7&SAMLRequest=');

    // And the deflate stream still survives the round trip, which is what the encoding choice is about.
    expect(sentAuthnRequest($location)->query('/samlp:AuthnRequest')?->length)->toBe(1);
});

it('mints a distinct request for every visit', function (): void {
    // Single-use is enforced at the ACS, but it is only meaningful if two sign-ins cannot collide on one
    // row. `request_id` is UNIQUE GLOBALLY, so a repeat would surface here as a 500 rather than as a
    // silent overwrite.
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::factory()->active()->create();

    $this->get(loginUrl())->assertRedirect();
    $this->get(loginUrl())->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $ids = SsoAuthRequest::query()->pluck('request_id')->all();

    expect($ids)->toHaveCount(2)
        ->and(array_unique($ids))->toHaveCount(2);
});

it('carries its own rate limiter, because an anonymous caller can mint rows here', function (): void {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($candidate): bool => $candidate->getName() === 'sso.login');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:saml-login');
});

it('gives the metadata endpoint its own limiter too — it had none until P1d', function (): void {
    // ⚠️ THE THIRD PROTOCOL ROUTE WAS UNBOUNDED WHILE THE COMMENT BESIDE IT JUSTIFIED LIMITERS FOR "BOTH
    // HALVES" OF THE ROUND TRIP AND NEVER MENTIONED IT. It is unauthenticated, reachable by anyone holding
    // a hostname, and composes a DOM document per request — so the asymmetry reads as an oversight rather
    // than a decision. A SEPARATE bucket, for the reason the provider's own comment gives: sharing one
    // would halve whichever ceiling an operator thought they were setting.
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($candidate): bool => $candidate->getName() === 'sso.metadata');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:saml-metadata')
        ->and($route->gatherMiddleware())->not->toContain('throttle:saml-login');
});

it('registers every SAML limiter it names, so a throttle alias cannot be a typo', function (): void {
    // A `throttle:` middleware naming a limiter nobody registered does not fail loudly — it resolves to an
    // unlimited passthrough, so the two assertions above would stay green over a bound that does not exist.
    foreach (['saml-login', 'saml-acs', 'saml-metadata', 'saml-step-up', 'saml-step-up-complete', 'saml-login-complete'] as $name) {
        expect(RateLimiter::limiter($name))->not->toBeNull("limiter [{$name}] is not registered");
    }
});

it('remembers in this browser which flow it started, because the ACS cannot ask', function (): void {
    // ⚠️ THIS ENDPOINT WROTE ZERO SESSION KEYS UNTIL P1e, AND THAT ABSENCE WAS THE DEFECT. The row it mints
    // proves this SP started the flow; nothing proved WHO was holding it, so an attacker with an account at
    // the tenant's own directory could obtain a real assertion and have a victim's browser complete their
    // sign-in. The ACS cannot make the comparison — it is a cross-site POST that carries no cookie — so the
    // binding is written here, on the one leg before it that the browser's session reaches.
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::factory()->active()->create();

    $this->get(loginUrl())->assertRedirect();

    $request = mintedRequest($this->tenant, $this->admin);

    // The flow's OWN id, never a flag: a hop that merely required the session to hold something would be
    // defeated by luring the victim through this route first to populate their session.
    expect(SsoSession::hasPendingLogin(session()->driver(), $request->request_id))->toBeTrue()
        ->and(session()->driver()->get(SsoSession::PENDING_LOGIN_IDS))->toBe([$request->request_id]);
});
