<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\SsoConnectionStatus;
use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Sso\SsoAuthnRequestBuilder;
use App\Support\Tenancy\TenantContext;
use Database\Factories\SsoConnectionFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Sso\FakeIdp;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| /settings/sso (P1a, ADR-0016) — the session-authed SSO configuration surface. Route-level Inertia render +
| prop shape (SsoConnectionPresenter), the import/policy/status/delete mutations (delegating to
| SsoConnectionService), the tenant.settings.manage gate, and the DELIBERATELY ASYMMETRIC feature gate.
|
| ⚠️ UNLIKE `domains`, `sso_connections` IS STRICT FORCE RLS. Two consequences for everything below. The
| GUC is torn down after every HTTP request, so a direct model read or write BETWEEN requests needs a fresh
| enterTenant() — and forgetting it does not error, it silently sees or writes zero rows, which surfaces as
| a failure on the wrong assertion. And the isolation itself is the database's, so the cross-tenant case
| here proves a policy rather than a controller filter.
|
| ⚠️ Enterprise is the ONLY tier carrying `sso_saml` (PlanCatalog:65). Without assignPlanTier() the tenant
| resolves no plan at all, RequireFeature passes through by design, and every gate assertion below would be
| vacuous in the direction that makes the gate look present.
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

    $this->viewer = User::factory()->create();
    makeActiveMember($this->viewer, 'viewer');

    assignPlanTier(PlanTier::Enterprise);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function ssoUrl(string $suffix = ''): string
{
    return 'http://acme.meridian.test/settings/sso'.$suffix;
}

/** Re-enter the tenant and hand back the stored connection — the GUC teardown guard, in one place. */
function storedConnection(Tenant $tenant, User $actor): ?SsoConnection
{
    enterTenant($tenant->id, $actor->id);

    return SsoConnection::query()->first();
}

/*
|--------------------------------------------------------------------------
| Render
|--------------------------------------------------------------------------
*/

it('renders the page with the service-provider values an admin must paste into their identity provider', function (): void {
    $this->withoutVite();

    $this->actingAs($this->admin)
        ->get(ssoUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Sso', false)
            ->where('data', null)
            ->where('sp.entity_id', 'http://acme.meridian.test/sso/saml/metadata')
            ->where('sp.acs_url', 'http://acme.meridian.test/sso/saml/acs')
            // P1b. Currently the ONLY user-facing way into the flow, so its absence would take SSO offline
            // with every other gate green.
            ->where('sp.login_url', 'http://acme.meridian.test/sso/saml/login')
            ->where('can.configure', true)
            ->where('can.manage', true)
            ->where('entitled', true)
            ->has('roles', 4));
});

it('offers the four assignable roles and never owner', function (): void {
    $this->withoutVite();

    $this->actingAs($this->admin)
        ->get(ssoUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('roles.0.value', 'admin')
            ->where('roles.3.value', 'viewer')
            ->where('roles', fn ($roles) => ! in_array('owner', array_column($roles->toArray(), 'value'), true)));
});

/*
|--------------------------------------------------------------------------
| Import
|--------------------------------------------------------------------------
*/

it('imports a metadata document and leaves the connection in draft', function (): void {
    confirmPasswordNow();

    $this->actingAs($this->admin)
        ->put(ssoUrl('/idp-metadata'), ['metadata_xml' => idpMetadataXml()])
        ->assertRedirect()
        ->assertSessionHas('toast.type', 'success');

    $connection = storedConnection($this->tenant, $this->admin);

    expect($connection)->not->toBeNull()
        ->and($connection->status)->toBe(SsoConnectionStatus::Draft)
        ->and($connection->idp_entity_id)->toBe('https://idp.example.com/saml2')
        ->and($connection->idp_sso_url)->toBe('https://idp.example.com/saml2/sso')
        ->and($connection->idp_certificates)->toHaveCount(1)
        ->and($connection->idp_certificates_fingerprint)->toHaveLength(64)
        ->and($connection->idp_metadata_sha256)->toHaveLength(64)
        ->and($connection->idp_metadata_imported_at)->not->toBeNull()
        ->and($connection->name_id_format)->toBe('urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress')
        ->and((string) $connection->created_by)->toBe((string) $this->admin->id);
});

it('replaces the whole identity-provider half on re-import and never silently keeps a retired key', function (): void {
    // The failure this prevents: the new SSO URL lands, the old certificate stays, and the tenant is left
    // trusting a key their provider has retired. SsoMetadataParser's docblock names it.
    confirmPasswordNow();
    $first = SsoConnectionFactory::certificate();
    $second = SsoConnectionFactory::certificate(400);

    $this->actingAs($this->admin)->put(ssoUrl('/idp-metadata'), ['metadata_xml' => idpMetadataXml($first)]);
    $before = storedConnection($this->tenant, $this->admin);
    $beforeFingerprint = $before->idp_certificates_fingerprint;

    confirmPasswordNow();
    $this->actingAs($this->admin)->put(ssoUrl('/idp-metadata'), ['metadata_xml' => idpMetadataXml($second)]);
    $after = storedConnection($this->tenant, $this->admin);

    expect($after->idp_certificates)->toBe([$second])
        ->and($after->idp_certificates_fingerprint)->not->toBe($beforeFingerprint);
});

it('keeps an active connection active across a key rotation', function (): void {
    // THE MOST IMPORTANT BEHAVIOURAL ASSERTION IN THIS SLICE. Re-importing is how a tenant picks up a
    // rotated signing key; a rotation that silently took SSO offline would be an outage caused by doing
    // exactly the right thing. `status` is never in importMetadata()'s attribute array.
    confirmPasswordNow();
    $this->actingAs($this->admin)->put(ssoUrl('/idp-metadata'), ['metadata_xml' => idpMetadataXml()]);
    $this->actingAs($this->admin)->patch(ssoUrl('/status'), ['status' => 'active']);

    confirmPasswordNow();
    $this->actingAs($this->admin)
        ->put(ssoUrl('/idp-metadata'), ['metadata_xml' => idpMetadataXml(SsoConnectionFactory::certificate(400))]);

    expect(storedConnection($this->tenant, $this->admin)->status)->toBe(SsoConnectionStatus::Active);
});

it('updates the one connection rather than creating a second', function (): void {
    confirmPasswordNow();
    $this->actingAs($this->admin)->put(ssoUrl('/idp-metadata'), ['metadata_xml' => idpMetadataXml()])->assertRedirect();
    confirmPasswordNow();
    $this->actingAs($this->admin)->put(ssoUrl('/idp-metadata'), ['metadata_xml' => idpMetadataXml()])->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(SsoConnection::query()->count())->toBe(1);

    // And the constraint underneath is what makes the service's first()-then-fill() necessary rather than
    // merely tidy: a second row is refused by the database, not by convention.
    expect(fn () => SsoConnection::factory()->create())
        ->toThrow(QueryException::class);
});

it('surfaces a malformed metadata paste as a field error carrying the parser’s own words', function (string $xml, string $fragment): void {
    confirmPasswordNow();

    $this->actingAs($this->admin)
        ->put(ssoUrl('/idp-metadata'), ['metadata_xml' => $xml])
        ->assertRedirect()
        ->assertSessionHasErrors('metadata_xml');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(SsoConnection::query()->count())->toBe(0);
    expect(session('errors')->first('metadata_xml'))->toContain($fragment);
})->with([
    'not xml at all' => ['this is not xml', 'well-formed'],
    'a DOCTYPE' => ['<!DOCTYPE x><EntityDescriptor/>', 'DOCTYPE'],
    'service-provider metadata' => [
        '<EntityDescriptor xmlns="urn:oasis:names:tc:SAML:2.0:metadata" entityID="x"><SPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol"/></EntityDescriptor>',
        'service-provider metadata',
    ],
]);

it('refuses a metadata document larger than the configured bound before it reaches the parser', function (): void {
    // Measured before this bound existed: 16 MB of well-formed XML peaks at ~38 MB of DOM on top of the
    // source string — a fatal, which has no toast and no 422. Two gates; this exercises the request's.
    confirmPasswordNow();
    config()->set('saml.max_metadata_bytes', 1024);

    $this->actingAs($this->admin)
        ->put(ssoUrl('/idp-metadata'), ['metadata_xml' => str_repeat('a', 2048)])
        ->assertSessionHasErrors('metadata_xml');
});

/*
|--------------------------------------------------------------------------
| Policy
|--------------------------------------------------------------------------
*/

it('patches the policy half without touching the trust anchor', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    $connection = SsoConnection::factory()->create();
    $fingerprint = $connection->idp_certificates_fingerprint;
    $importedAt = $connection->idp_metadata_imported_at;

    $this->actingAs($this->admin)
        ->patch(ssoUrl(), [
            'jit_provisioning_enabled' => false,
            'default_role_name' => 'reviewer',
            'attribute_map' => ['email' => 'urn:oid:0.9.2342.19200300.100.1.3', 'name' => ''],
        ])
        ->assertRedirect()
        ->assertSessionHas('toast.type', 'success');

    $updated = storedConnection($this->tenant, $this->admin);

    expect($updated->jit_provisioning_enabled)->toBeFalse()
        ->and($updated->default_role_name)->toBe('reviewer')
        // The cleared input is REMOVED rather than stored as an empty mapping that points nowhere.
        ->and($updated->attribute_map)->toBe(['email' => 'urn:oid:0.9.2342.19200300.100.1.3'])
        ->and($updated->idp_certificates_fingerprint)->toBe($fingerprint)
        ->and($updated->idp_metadata_imported_at->timestamp)->toBe($importedAt->timestamp);
});

it('refuses a default role outside the CHECK’s vocabulary, including owner', function (string $role): void {
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::factory()->create();

    $this->actingAs($this->admin)
        ->patch(ssoUrl(), ['default_role_name' => $role])
        ->assertSessionHasErrors('default_role_name');
})->with(['owner', 'superuser', 'Admin', '']);

it('refuses to be put back into draft', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::factory()->active()->create();

    $this->actingAs($this->admin)
        ->patch(ssoUrl('/status'), ['status' => 'draft'])
        ->assertSessionHasErrors('status');
});

/*
|--------------------------------------------------------------------------
| Status — and the endpoint this whole slice exists to make reachable
|--------------------------------------------------------------------------
*/

it('activates a connection and makes the service-provider metadata endpoint serve', function (): void {
    // THE ASSERTION THAT JUSTIFIES THE SLICE. P1a (1/2) shipped /sso/saml/metadata with no writer, so it
    // 404'd for every tenant — an endpoint with no consumer. This is the first time it can answer.
    $this->withoutVite();
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::factory()->create();

    $this->get('http://acme.meridian.test/sso/saml/metadata')->assertNotFound();

    $this->actingAs($this->admin)->patch(ssoUrl('/status'), ['status' => 'active'])->assertRedirect();

    $response = $this->get('http://acme.meridian.test/sso/saml/metadata')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/samlmetadata+xml');
    expect($response->getContent())->toContain('http://acme.meridian.test/sso/saml/acs');
});

it('completes the whole round trip once a connection is active — P1a’s canary, discharged', function (): void {
    // ⚠️ THIS WAS P1a's CANARY AND IT HAS BEEN REWRITTEN BY HAND, WHICH WAS THE POINT OF LEAVING IT.
    // It used to assert that `/sso/saml/login` and `/sso/saml/acs` both 404'd while a connection was Active
    // — the honest statement of a slice that published an ACS location nothing could reach. P1b routes
    // both, so the assertion is now the thing the canary was standing in for: activate, leave, come back
    // with a signed assertion, and be somebody.
    //
    // It lives HERE rather than only in SsoAcsWebTest because this file owns the SETTINGS surface, and the
    // claim being made is about that surface: "Active" now means what the status card says it means.
    enterTenant($this->tenant->id, $this->admin->id);
    FakeIdp::connection();

    expect(config('saml.allow_unsolicited'))->toBeFalse();

    $redirect = $this->get('http://acme.meridian.test/sso/saml/login')->assertRedirect();

    $query = [];
    parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);

    $document = new DOMDocument;
    $document->loadXML(SsoAuthnRequestBuilder::decodeTransport((string) ($query['SAMLRequest'] ?? '')));
    $requestId = (string) $document->documentElement?->getAttribute('ID');

    $assertion = (new FakeIdp(
        'http://acme.meridian.test/sso/saml/acs',
        'http://acme.meridian.test/sso/saml/metadata',
        $requestId,
    ))->as('grace@acme.test')->response();

    $this->post('http://acme.meridian.test/sso/saml/acs', ['SAMLResponse' => $assertion])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticated();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(SsoConnection::query()->value('last_login_at'))->not->toBeNull();
});

it('disables and re-enables without a re-import', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::factory()->active()->create();

    $this->actingAs($this->admin)->patch(ssoUrl('/status'), ['status' => 'disabled'])->assertRedirect();
    $this->get('http://acme.meridian.test/sso/saml/metadata')->assertNotFound();

    $this->actingAs($this->admin)->patch(ssoUrl('/status'), ['status' => 'active'])->assertRedirect();
    $this->get('http://acme.meridian.test/sso/saml/metadata')->assertOk();
});

/*
|--------------------------------------------------------------------------
| Delete
|--------------------------------------------------------------------------
*/

it('deletes the connection and everything it authorised', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    $connection = SsoConnection::factory()->active()->create();

    DB::table('sso_auth_requests')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'sso_connection_id' => $connection->id,
        'request_id' => '_'.bin2hex(random_bytes(16)),
        'intent' => 'login',
        'user_id' => null,
        'force_authn' => false,
        'issued_at' => now(),
        'expires_at' => now()->addMinutes(10),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($this->admin)->delete(ssoUrl())->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(SsoConnection::query()->count())->toBe(0)
        // The composite FK's cascade, tenant-confined by construction (ADR-0002 §D5).
        ->and(DB::table('sso_auth_requests')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The certificate never leaves the service layer
|--------------------------------------------------------------------------
*/

it('never puts the certificate body in a prop', function (): void {
    $this->withoutVite();
    enterTenant($this->tenant->id, $this->admin->id);
    $certificate = SsoConnectionFactory::certificate();
    SsoConnection::factory()->withCertificates([$certificate])->create();

    $response = $this->actingAs($this->admin)->get(ssoUrl())->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->missing('data.idp_certificates')
        ->where('data.fingerprint_short', fn (string $short): bool => mb_strlen($short) === 12)
        ->has('data.certificates', 1));

    // The assertion that actually matters: `missing()` walks named keys, and a nested leak or a stray
    // toArray() would slip past it. The raw body cannot.
    expect($response->getContent())->not->toContain($certificate);
});

/*
|--------------------------------------------------------------------------
| The gates
|--------------------------------------------------------------------------
*/

it('gates the trust-anchor writes on the Enterprise feature but leaves the read, the switch and the delete open', function (): void {
    // ADR-0016 §D5, and the reason it is not symmetry for its own sake: a tenant downgraded off Enterprise
    // still has an identity provider pointed at this SP. Gating the read would leave them unable to see it;
    // gating the SWITCH would leave "delete the trust anchor" as their only action, which is the inverse of
    // an escape hatch and the mistake the first draft of this row made.
    $this->withoutVite();
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::factory()->active()->create();

    assignPlanTier(PlanTier::Business);
    app()->forgetInstance(EntitlementService::class);

    confirmPasswordNow();
    $this->actingAs($this->admin)
        ->put(ssoUrl('/idp-metadata'), ['metadata_xml' => idpMetadataXml()])
        ->assertRedirect()->assertSessionHas('toast.type', 'error');

    $this->actingAs($this->admin)
        ->patch(ssoUrl(), ['default_role_name' => 'admin'])
        ->assertRedirect()->assertSessionHas('toast.type', 'error');

    $this->actingAs($this->admin)
        ->get(ssoUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('entitled', false)
            ->where('can.configure', false)
            ->where('can.manage', true)
            ->has('data'));

    // Switching OFF still works — the escape hatch.
    $this->actingAs($this->admin)->patch(ssoUrl('/status'), ['status' => 'disabled'])->assertRedirect();
    expect(storedConnection($this->tenant, $this->admin)->status)->toBe(SsoConnectionStatus::Disabled);

    // Switching back ON does not: undo always, redo only on the plan.
    $this->actingAs($this->admin)
        ->patch(ssoUrl('/status'), ['status' => 'active'])
        ->assertSessionHas('toast.type', 'error');
    expect(storedConnection($this->tenant, $this->admin)->status)->toBe(SsoConnectionStatus::Disabled);

    // And the delete works, which is the other half of the point.
    $this->actingAs($this->admin)->delete(ssoUrl())->assertRedirect();
    enterTenant($this->tenant->id, $this->admin->id);
    expect(SsoConnection::query()->count())->toBe(0);
});

it('names the feature in a refusal instead of the raw entitlement key', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::factory()->create();
    assignPlanTier(PlanTier::Business);
    app()->forgetInstance(EntitlementService::class);

    $this->actingAs($this->admin)->patch(ssoUrl(), ['default_role_name' => 'admin']);

    $message = (string) session('toast.message');
    expect($message)->toContain('single sign-on')->and($message)->not->toContain('sso_saml');
});

it('refuses every route to a member without tenant.settings.manage', function (string $method, string $suffix): void {
    enterTenant($this->tenant->id, $this->admin->id);
    SsoConnection::factory()->create();

    $this->actingAs($this->viewer)
        ->call($method, ssoUrl($suffix))
        ->assertForbidden();
})->with([
    'show' => ['GET', ''],
    'import' => ['PUT', '/idp-metadata'],
    'update' => ['PATCH', ''],
    'status' => ['PATCH', '/status'],
    'destroy' => ['DELETE', ''],
]);

it('requires a recent password confirmation before the trust anchor may be rewritten', function (): void {
    // Rewriting idp_certificates is a complete authentication takeover for the tenant — a larger blast
    // radius than members.role, which has carried step-up since I8a.
    $this->actingAs($this->admin)
        ->put(ssoUrl('/idp-metadata'), ['metadata_xml' => idpMetadataXml()])
        ->assertRedirect(route('password.confirm'));

    enterTenant($this->tenant->id, $this->admin->id);
    expect(SsoConnection::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Isolation
|--------------------------------------------------------------------------
*/

it('shows one tenant’s connection to that tenant alone', function (): void {
    $this->withoutVite();

    $other = Tenant::create(['name' => 'Globex', 'slug' => 'globex', 'default_locale' => 'en']);
    $other->domains()->create(['domain' => 'globex']);
    enterTenant($other->id);
    SsoConnection::factory()->active()->create();

    enterTenant($this->tenant->id, $this->admin->id);

    $this->actingAs($this->admin)
        ->get(ssoUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('data', null));
});
