<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\CustomDomainService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| /domains (H22b) — the session-authed custom-domain admin UI over the H22a lifecycle. Route-level Inertia
| render + prop shape (DomainPresenter), the claim/verify/primary/release mutations (delegating to
| CustomDomainService), the tenant.settings.manage gate, and the DELIBERATELY ASYMMETRIC feature gate.
| Session auth (actingAs), NOT tokens — a session carries no Sanctum token, so there is no `ability:` here.
|
| ⚠️ `domains` IS RLS-EXEMPT. It is the table read to decide which tenant a request IS, so scoping it by
| tenant would be circular — which means there is NO database backstop under any query on this surface. The
| controller's explicit tenant_id filter is the entire isolation, and the cross-tenant cases below are the
| most important assertions in this file for exactly that reason.
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

    // RequireFeature FAILS OPEN when currentPlan() is null, so every gate assertion below is worthless
    // without an assigned tier. Business is the tier that carries `custom_domain`.
    assignPlanTier(PlanTier::Business);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function domainWebUrl(string $suffix = ''): string
{
    return 'http://acme.meridian.test/domains'.$suffix;
}

it('renders the index with each domain, its DNS record and the can flags', function (): void {
    $this->withoutVite();
    app(CustomDomainService::class)->claim($this->tenant, 'forms.acme-example.com');

    $this->actingAs($this->admin)
        ->get(domainWebUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('domains/Index', false)
            ->has('data', 1)
            ->where('data.0.domain', 'forms.acme-example.com')
            ->where('data.0.status', 'pending')
            ->where('data.0.verification.type', 'TXT')
            ->where('data.0.verification.name', '_meridian-challenge.forms.acme-example.com')
            ->where('data.0.awaiting_operator', false)
            ->where('data.0.is_primary', false)
            // The always-available fallback, so the page can say where forms are served TODAY.
            ->has('appHost')
            ->where('can.create', true)
            ->where('can.manage', true)
            ->where('entitled', true));
});

it('never lists the tenant subdomain row, which is not a custom domain', function (): void {
    // `domains` holds two kinds of row discriminated by the dot (ADR-0012 §D3). Showing the label row here
    // would offer a Remove button on the tenant's own workspace address.
    $this->withoutVite();
    app(CustomDomainService::class)->claim($this->tenant, 'forms.acme-example.com');

    $this->actingAs($this->admin)
        ->get(domainWebUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('data', 1)->where('data.0.domain', 'forms.acme-example.com'));
});

it('lists only the requesting tenants domains', function (): void {
    // There is no RLS under this read. Without the presenter's explicit tenant filter it would list every
    // custom domain in the deployment.
    $this->withoutVite();
    $beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'default_locale' => 'en']);
    $beta->domains()->create(['domain' => 'beta']);
    customDomain($beta, 'forms.beta-example.com');
    app(CustomDomainService::class)->claim($this->tenant, 'forms.acme-example.com');

    $this->actingAs($this->admin)
        ->get(domainWebUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('data', 1)->where('data.0.domain', 'forms.acme-example.com'));
});

it('claims a domain and lands back on the list with the record to publish', function (): void {
    $this->actingAs($this->admin)
        ->post(domainWebUrl(), ['domain' => 'forms.acme-example.com'])
        ->assertRedirect(domainWebUrl())
        ->assertSessionHas('toast.type', 'success');

    $row = Domain::unscopedQuery()->where('domain', 'forms.acme-example.com')->sole();

    expect($row->tenant_id)->toBe($this->tenant->id)
        ->and($row->verification_token)->not->toBeNull()
        ->and($row->verified_at)->toBeNull()
        ->and($row->activated_at)->toBeNull()
        ->and($row->is_primary)->toBeFalse();
});

it('ignores verification fields smuggled into the claim form', function (): void {
    // stancl's Domain model is `$guarded = []`, so this is the web half of the belt-and-braces the API
    // surface already carries: the FormRequest accepts only `domain`, AND the service builds its attribute
    // array explicitly. `is_primary` is the field H22b adds to that hazard.
    $this->actingAs($this->admin)
        ->post(domainWebUrl(), [
            'domain' => 'forms.acme-example.com',
            'verified_at' => now()->toIso8601String(),
            'activated_at' => now()->toIso8601String(),
            'is_primary' => true,
        ])
        ->assertRedirect(domainWebUrl());

    $row = Domain::unscopedQuery()->where('domain', 'forms.acme-example.com')->sole();

    expect($row->verified_at)->toBeNull()
        ->and($row->activated_at)->toBeNull()
        ->and($row->is_primary)->toBeFalse();
});

it('rejects a hostname under a central domain with a field error, not an error page', function (): void {
    // The same ClaimableDomain rule the API uses — one predicate for what may be claimed and what routes.
    $this->actingAs($this->admin)
        ->post(domainWebUrl(), ['domain' => 'x.meridian.test'])
        ->assertSessionHasErrors('domain');

    expect(Domain::unscopedQuery()->where('domain', 'x.meridian.test')->exists())->toBeFalse();
});

it('verifies on demand and says what happens next rather than only that it worked', function (): void {
    $domain = app(CustomDomainService::class)->claim($this->tenant, 'forms.acme-example.com');
    fakeDns([
        '_meridian-challenge.forms.acme-example.com' => ['meridian-domain-verification='.$domain->verification_token],
    ]);

    $this->actingAs($this->admin)
        ->post(domainWebUrl('/forms.acme-example.com/verify'))
        ->assertRedirect()
        ->assertSessionHas('toast.type', 'success');

    expect($domain->fresh()->isVerified())->toBeTrue()
        // Proving control does NOT put the hostname into service, and the flash has to say so — a tenant
        // told only "verified" will reasonably point live traffic at an origin with no certificate.
        ->and($domain->fresh()->activated_at)->toBeNull();

    expect(session('toast.message'))->toContain('put it into service');
});

it('reports a failed check as an instruction the tenant can act on', function (): void {
    app(CustomDomainService::class)->claim($this->tenant, 'forms.acme-example.com');
    fakeDns(['_meridian-challenge.forms.acme-example.com' => ['meridian-domain-verification=someone-elses-token']]);

    $this->actingAs($this->admin)
        ->post(domainWebUrl('/forms.acme-example.com/verify'))
        ->assertRedirect()
        ->assertSessionHas('toast.type', 'error');

    // `mismatch` and `not_found` call for DIFFERENT actions (fix a typo vs. wait), which is the whole
    // reason DomainVerificationFailure splits them — collapsing them in the UI throws that away.
    expect(session('toast.message'))->toContain('does not match');
});

it('makes a live domain primary and demotes the previous one', function (): void {
    $first = customDomain($this->tenant, 'forms.acme-example.com');
    $second = customDomain($this->tenant, 'apply.acme-example.com');
    app(CustomDomainService::class)->makePrimary($first);

    $this->actingAs($this->admin)
        ->post(domainWebUrl('/apply.acme-example.com/primary'))
        ->assertRedirect()
        ->assertSessionHas('toast.type', 'success');

    expect($first->fresh()->is_primary)->toBeFalse()
        ->and($second->fresh()->is_primary)->toBeTrue();
});

it('refuses to make a domain primary before an operator has put it into service', function (): void {
    // A TOAST, not a 422: the only way to reach this is a stale page whose row left service between render
    // and click, and an error page would lose the rest of the list over a race. The refusal is real either
    // way — the service checks, and domains_primary_requires_live_chk checks underneath it.
    $domain = customDomain($this->tenant, 'forms.acme-example.com', verified: true, activated: false);

    $this->actingAs($this->admin)
        ->post(domainWebUrl('/forms.acme-example.com/primary'))
        ->assertRedirect()
        ->assertSessionHas('toast.type', 'error');

    expect($domain->fresh()->is_primary)->toBeFalse()
        ->and($domain->fresh()->activated_at)->toBeNull();
});

it('releases a custom domain but refuses the tenant subdomain row', function (): void {
    customDomain($this->tenant, 'forms.acme-example.com');

    $this->actingAs($this->admin)
        ->delete(domainWebUrl('/forms.acme-example.com'))
        ->assertRedirect(domainWebUrl())
        ->assertSessionHas('toast.type', 'success');

    expect(Domain::unscopedQuery()->where('domain', 'forms.acme-example.com')->exists())->toBeFalse();

    // Deleting the label row would take the whole tenant offline with no way back through this surface.
    $this->actingAs($this->admin)
        ->delete(domainWebUrl('/acme'))
        ->assertRedirect()
        ->assertSessionHas('toast.type', 'error');

    expect(Domain::unscopedQuery()->where('domain', 'acme')->exists())->toBeTrue();
});

it('404s another tenants domain rather than acting on it', function (string $method, string $suffix): void {
    // THE assertion for this surface. `domains` has no RLS, so without the controller's explicit tenant_id
    // filter every one of these would resolve and act on another tenant's row.
    $beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'default_locale' => 'en']);
    $beta->domains()->create(['domain' => 'beta']);
    customDomain($beta, 'forms.beta-example.com');

    $this->actingAs($this->admin)
        ->call($method, domainWebUrl("/forms.beta-example.com{$suffix}"))
        ->assertNotFound();

    expect(Domain::unscopedQuery()->where('domain', 'forms.beta-example.com')->exists())->toBeTrue();
})->with([
    'verify' => ['POST', '/verify'],
    'primary' => ['POST', '/primary'],
    'destroy' => ['DELETE', ''],
]);

it('gates the writes on the Business feature but leaves the read and the delete open', function (): void {
    // ADR-0012 §D9, and the reason it is not symmetry for its own sake: a tenant downgraded off Business
    // keeps a LIVE, RESOLVING hostname. Gating the read would leave it with a domain it can see nowhere
    // and remove nowhere, still serving its respondents.
    $this->withoutVite();
    assignPlanTier(PlanTier::Professional);
    customDomain($this->tenant, 'forms.acme-example.com');

    $this->actingAs($this->admin)->post(domainWebUrl(), ['domain' => 'other.acme-example.com'])
        ->assertRedirect()->assertSessionHas('toast.type', 'error');
    $this->actingAs($this->admin)->post(domainWebUrl('/forms.acme-example.com/verify'))
        ->assertRedirect()->assertSessionHas('toast.type', 'error');
    $this->actingAs($this->admin)->post(domainWebUrl('/forms.acme-example.com/primary'))
        ->assertRedirect()->assertSessionHas('toast.type', 'error');

    // The read still renders, and it tells the page to hide the affordances rather than 402 the tenant.
    $this->actingAs($this->admin)
        ->get(domainWebUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('entitled', false)
            ->where('can.create', false)
            ->where('can.manage', true)
            ->has('data', 1));

    // And the delete works, which is the whole point of the asymmetry.
    $this->actingAs($this->admin)->delete(domainWebUrl('/forms.acme-example.com'))->assertRedirect();

    expect(Domain::unscopedQuery()->where('domain', 'forms.acme-example.com')->exists())->toBeFalse();
});

it('refuses every route to a member without tenant.settings.manage', function (string $method, string $suffix): void {
    customDomain($this->tenant, 'forms.acme-example.com');

    $this->actingAs($this->viewer)
        ->call($method, domainWebUrl($suffix))
        ->assertForbidden();
})->with([
    'index' => ['GET', ''],
    'store' => ['POST', ''],
    'verify' => ['POST', '/forms.acme-example.com/verify'],
    'primary' => ['POST', '/forms.acme-example.com/primary'],
    'destroy' => ['DELETE', '/forms.acme-example.com'],
]);

it('links the page from settings once a tenant holds a domain, and not before', function (): void {
    // ADR-0012 §D9's escape hatch. The sidebar item requires the `custom_domain` feature, so after a
    // downgrade this link is the only remaining path to a hostname that is still resolving — and it must
    // stay hidden for everyone else, because Business is held from sale and an entry point to an unbuyable
    // feature is worse than none.
    $this->withoutVite();

    $this->actingAs($this->admin)
        ->get('http://acme.meridian.test/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('customDomains.count', 0));

    // The GUC is torn down after every HTTP request, so the RLS-scoped subscriptions write below needs the
    // tenant re-entered. `domains` is RLS-exempt and would have succeeded either way, which is exactly how
    // this bites: only one of the two lines fails, and it is not the one being tested.
    enterTenant($this->tenant->id, $this->admin->id);
    customDomain($this->tenant, 'forms.acme-example.com');
    assignPlanTier(PlanTier::Professional);

    $this->actingAs($this->admin)
        ->get('http://acme.meridian.test/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('customDomains.count', 1));
});
