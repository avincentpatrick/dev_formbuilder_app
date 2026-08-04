<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\Domain;
use App\Models\Tenant;
use App\Services\Tenancy\CustomDomainService;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
| H22a — the /api/v1/domains surface. Ability tests use REAL tokens with withToken(), never actingAs():
| the Sanctum guard's first-party web-guard loop would resolve an actingAs() user to a TransientToken
| that passes every ability check (the ApiV1Test premise).
|
| `domains` is RLS-EXEMPT — it is the table read to decide which tenant a request IS — so there is NO
| database backstop under these queries. The controller's explicit tenant_id filter is the only isolation
| here, which is why the cross-tenant cases below are the most important assertions in the file.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A Business-entitled tenant plus an admin token carrying manage:domains. */
function domainApiToken(string $slug = 'acme', PlanTier $tier = PlanTier::Business): string
{
    $tenant = inboxTenant($slug);
    enterTenant($tenant->id);
    $admin = apiMember('admin');
    // RequireFeature FAILS OPEN when currentPlan() is null, so a feature-gate test proves nothing
    // without an assigned plan.
    assignPlanTier($tier);

    return $admin->createToken('ci', [ApiAbilities::MANAGE_DOMAINS])->plainTextToken;
}

it('claims a domain and returns the DNS record to publish', function (): void {
    $token = domainApiToken();

    $response = $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/domains', ['domain' => 'forms.acme-example.com'])
        ->assertCreated();

    expect($response->json('data.domain'))->toBe('forms.acme-example.com')
        ->and($response->json('data.status'))->toBe('pending')
        ->and($response->json('data.verification.type'))->toBe('TXT')
        ->and($response->json('data.verification.name'))->toBe('_meridian-challenge.forms.acme-example.com')
        ->and($response->json('data.verification.value'))->toStartWith('meridian-domain-verification=')
        ->and($response->json('data.awaiting_operator'))->toBeFalse();
});

it('ignores verification fields smuggled into the claim body', function (): void {
    // stancl's Domain model is `$guarded = []` and H22a deliberately did not narrow it (sixty fixture
    // sites depend on it), so this is belt and braces: the FormRequest accepts only `domain`, AND the
    // service builds its attribute array explicitly rather than passing request data through. Either
    // alone would be enough; neither alone is obviously enough to a future reader.
    $token = domainApiToken();

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/domains', [
            'domain' => 'forms.acme-example.com',
            'verified_at' => now()->toIso8601String(),
            'activated_at' => now()->toIso8601String(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    $row = Domain::unscopedQuery()->where('domain', 'forms.acme-example.com')->sole();

    expect($row->verified_at)->toBeNull()
        ->and($row->activated_at)->toBeNull();
});

it('refuses a hostname under a central domain', function (): void {
    // The routing predicate, enforced at claim time. `x.meridian.test` would be classified as a subdomain
    // BEFORE any database read and resolved by the label `x`, so the row could never be reached — and
    // reserving it would let one tenant squat a name that resolves to another.
    $token = domainApiToken();

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/domains', ['domain' => 'x.meridian.test'])
        ->assertStatus(422);
});

it('verifies on demand once the TXT record is published', function (): void {
    $token = domainApiToken();
    $tenant = Tenant::query()->where('slug', 'acme')->sole();
    $domain = app(CustomDomainService::class)->claim($tenant, 'forms.acme-example.com');

    fakeDns([
        '_meridian-challenge.forms.acme-example.com' => [
            'meridian-domain-verification='.$domain->verification_token,
        ],
    ]);

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/domains/forms.acme-example.com/verify')
        ->assertOk()
        ->assertJsonPath('data.status', 'verified')
        // The counter-intuitive part, stated in the payload: proving control does NOT put the domain
        // into service. An operator must install a certificate and run `domains:activate` first.
        ->assertJsonPath('data.awaiting_operator', true);
});

it('lists only the requesting tenants custom domains', function (): void {
    $token = domainApiToken('acme');
    $beta = inboxTenant('beta');
    customDomain($beta, 'forms.beta-example.com');

    $acme = Tenant::query()->where('slug', 'acme')->sole();
    app(CustomDomainService::class)->claim($acme, 'forms.acme-example.com');

    $response = $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/domains')
        ->assertOk();

    expect($response->json('data.*.domain'))->toBe(['forms.acme-example.com']);
});

it('404s another tenants domain rather than acting on it', function (string $method, string $suffix): void {
    // THE assertion for this surface. `domains` has no RLS, so without the controller's explicit
    // tenant_id filter these would resolve and act on another tenant's row.
    $token = domainApiToken('acme');
    $beta = inboxTenant('beta');
    customDomain($beta, 'forms.beta-example.com');

    $this->withToken($token)
        ->json($method, "http://acme.meridian.test/api/v1/domains/forms.beta-example.com{$suffix}")
        ->assertNotFound();

    expect(Domain::unscopedQuery()->where('domain', 'forms.beta-example.com')->exists())->toBeTrue();
})->with([
    'verify' => ['POST', '/verify'],
    'destroy' => ['DELETE', ''],
    'primary' => ['POST', '/primary'],
]);

it('releases a custom domain but refuses to delete the tenant subdomain', function (): void {
    $token = domainApiToken();
    $tenant = Tenant::query()->where('slug', 'acme')->sole();
    app(CustomDomainService::class)->claim($tenant, 'forms.acme-example.com');

    $this->withToken($token)
        ->deleteJson('http://acme.meridian.test/api/v1/domains/forms.acme-example.com')
        ->assertNoContent();

    expect(Domain::unscopedQuery()->where('domain', 'forms.acme-example.com')->exists())->toBeFalse();

    // Deleting the label row would take the whole tenant offline with no way back through this surface.
    $this->withToken($token)
        ->deleteJson('http://acme.meridian.test/api/v1/domains/acme')
        ->assertStatus(422);

    expect(Domain::unscopedQuery()->where('domain', 'acme')->exists())->toBeTrue();
});

it('gates writes on the Business plan feature but leaves reads and deletes open', function (): void {
    // Verbatim the api_access precedent: a tenant downgraded off Business keeps a LIVE, resolving
    // hostname, so it must still be able to see it and take it down. Gating the read would strand them.
    $token = domainApiToken('acme', PlanTier::Professional);
    $tenant = Tenant::query()->where('slug', 'acme')->sole();
    customDomain($tenant, 'forms.acme-example.com');

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/domains', ['domain' => 'other.acme-example.com'])
        ->assertStatus(402);

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/domains/forms.acme-example.com/verify')
        ->assertStatus(402);

    // H22b's write joins the gated side, not the open one: choosing which host serves respondent links is
    // a use of the feature, while seeing and removing a host that is already resolving is the stranding
    // case §D9 exists to prevent.
    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/domains/forms.acme-example.com/primary')
        ->assertStatus(402);

    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/domains')
        ->assertOk();

    $this->withToken($token)
        ->deleteJson('http://acme.meridian.test/api/v1/domains/forms.acme-example.com')
        ->assertNoContent();
});

it('refuses a token that does not carry manage:domains', function (): void {
    // A NEW ability rather than a widening of manage:settings, so no already-minted token retroactively
    // gained the power to move the hostname a tenant's respondents visit.
    $tenant = inboxTenant('acme');
    enterTenant($tenant->id);
    $admin = apiMember('admin');
    assignPlanTier(PlanTier::Business);
    $token = $admin->createToken('ci', [ApiAbilities::MANAGE_SETTINGS])->plainTextToken;

    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/domains')
        ->assertForbidden();
});

it('refuses a member whose role lacks tenant.settings.manage', function (): void {
    // The `can:` gate re-checks the acting user's real permission, so an ability alone is not enough.
    $tenant = inboxTenant('acme');
    enterTenant($tenant->id);
    $viewer = apiMember('viewer');
    assignPlanTier(PlanTier::Business);
    $token = $viewer->createToken('ci', [ApiAbilities::MANAGE_DOMAINS])->plainTextToken;

    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/domains')
        ->assertForbidden();
});

it('makes a live domain primary and demotes the previous one', function (): void {
    // H22b. `domains_primary_per_tenant_unique` is a PARTIAL unique index, so the clear-then-set pair is
    // one transaction; a demotion that did not happen would surface here as a 23505 rather than as two
    // primaries, which is the point of putting the constraint underneath the service.
    $token = domainApiToken();
    $tenant = Tenant::query()->where('slug', 'acme')->sole();
    $first = customDomain($tenant, 'forms.acme-example.com');
    $second = customDomain($tenant, 'apply.acme-example.com');
    app(CustomDomainService::class)->makePrimary($first);

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/domains/apply.acme-example.com/primary')
        ->assertOk()
        ->assertJsonPath('data.is_primary', true);

    expect($first->fresh()->is_primary)->toBeFalse()
        ->and($second->fresh()->is_primary)->toBeTrue();
});

it('refuses to make a domain primary before an operator has put it into service', function (string $state, bool $verified): void {
    // THE assertion that keeps this endpoint off the activation path. ADR-0012 §D6 leaves
    // `php artisan domains:activate` as the only thing that can set `activated_at`, so a write a tenant CAN
    // reach must be unable to make an inert hostname serve anything — 422, and the row is untouched.
    $token = domainApiToken();
    $tenant = Tenant::query()->where('slug', 'acme')->sole();
    $domain = customDomain($tenant, 'forms.acme-example.com', verified: $verified, activated: false);

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/domains/forms.acme-example.com/primary')
        ->assertStatus(422);

    expect($domain->fresh()->is_primary)->toBeFalse()
        ->and($domain->fresh()->activated_at)->toBeNull();
})->with([
    'pending' => ['pending', false],
    'verified but awaiting the operator' => ['verified', true],
]);

it('exposes no activate endpoint', function (): void {
    // Putting a verified domain into service is `php artisan domains:activate`, run by whoever installed
    // the TLS certificate. Per-domain TLS is structurally Track B and Track B is deferred, so a tenant
    // able to activate its own domain could put its respondents on an origin with no certificate.
    $token = domainApiToken();
    $tenant = Tenant::query()->where('slug', 'acme')->sole();
    customDomain($tenant, 'forms.acme-example.com', verified: true, activated: false);

    $this->withToken($token)
        ->postJson('http://acme.meridian.test/api/v1/domains/forms.acme-example.com/activate')
        ->assertNotFound();

    expect(Domain::unscopedQuery()->where('domain', 'forms.acme-example.com')->sole()->activated_at)->toBeNull();
});
