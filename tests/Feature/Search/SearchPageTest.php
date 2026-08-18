<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| GET /search — the Inertia surface (Increment J1b).
|
| Props are asserted KEY BY KEY rather than with `has('data')`: a partial assertion passes against a prop
| that has silently lost half its shape, which is the rule `AuditLogPageTest` records.
|
| ⚠️ `$this->withoutVite()` ON EVERY GET THAT EXPECTS A 200, and `->component('search/Index', false)` — the
| `false` disables Inertia's page-file existence check. The Pest CI job never runs `npm run build`, so
| without the first the 200 arrives as a 500, and without the second a case-insensitive dev filesystem
| resolves a lookup Linux does not.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    app(FormService::class)->create($this->tenant, $this->owner, 'Clinic Intake');
    app(FormService::class)->create($this->tenant, $this->owner, 'Payroll Register');

    $this->actingAs($this->owner);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders results for a query', function (): void {
    $this->withoutVite()
        ->get('http://acme.meridian.test/search?q=clinic')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('search/Index', false)
            ->has('data')
            ->has('counts')
            ->has('filters.entities')
            ->where('filters.applied.q', 'clinic')
            ->where('filters.applied.entity', null)
            ->has('limits.per_entity')
            ->has('limits.single_entity')
            ->where('empty_reason', null)
        );
});

it('renders a cold visit with no query string at all as no_query, never a 422', function (): void {
    // The normal FIRST visit: the nav field's action is a bare /search. Every rule in SearchRequest is
    // `sometimes` for this reason — a 422 on an Inertia GET redirects "back", which on a cold visit is
    // nowhere at all.
    $this->withoutVite()
        ->get('http://acme.meridian.test/search')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('search/Index', false)
            ->where('empty_reason', 'no_query')
            ->where('filters.applied.q', null)
            ->where('data', [])
            ->where('counts', [])
        );
});

it('treats a whitespace-only query as no_query rather than no_matches', function (): void {
    $this->withoutVite()
        ->get('http://acme.meridian.test/search?q='.urlencode('   '))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('empty_reason', 'no_query'));
});

it('reports no_matches for a real query that finds nothing', function (): void {
    $this->withoutVite()
        ->get('http://acme.meridian.test/search?q=zzzznotathing')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('empty_reason', 'no_matches'));
});

it('does not 500 on a query made entirely of tsquery operators', function (): void {
    // The availability bug the sanitiser exists to prevent: an unsanitised `&` reaches `to_tsquery`, raises
    // SQLSTATE 42601, and the page 500s. Driven through a real HTTP request rather than the unit seam,
    // because that is where a future refactor would reintroduce it.
    $this->withoutVite()
        ->get('http://acme.meridian.test/search?q='.urlencode('a & | ! ( ) :*'))
        ->assertOk();
});

it('renders an unknown entity filter as an ordinary page, never a validation failure', function (): void {
    // A bookmarked or hand-edited ?entity=widgets. `entity` is deliberately a loose string, not
    // Rule::enum — once J1c adds arms, a link shared between two users with different permissions would
    // otherwise 422 for whichever of them cannot use that arm.
    $this->withoutVite()
        ->get('http://acme.meridian.test/search?q=clinic&entity=widgets')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.applied.entity', null));
});

it('clamps an over-long query instead of rejecting it', function (): void {
    $this->withoutVite()
        ->get('http://acme.meridian.test/search?q='.urlencode(str_repeat('clinic ', 200)))
        ->assertOk();
});

it('narrows to a single entity and raises that arm’s cap', function (): void {
    $this->withoutVite()
        ->get('http://acme.meridian.test/search?q=clinic&entity=form')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.applied.entity', 'form')
            ->has('data', 1)
            ->where('data.0.entity', 'form')
        );
});

it('requires authentication', function (): void {
    auth()->logout();

    $this->get('http://acme.meridian.test/search')->assertRedirect();
});

it('carries no can: or feature: gate on the route', function (): void {
    // Both absences are deliberate and are argued at the route. Authorization is entirely per-arm inside
    // SearchService: a route gate would be either too wide (letting a Reviewer's request reach an arm they
    // may not use) or too narrow (refusing a Viewer the submissions arm they hold). Asserted structurally so
    // that adding one "for symmetry" fails here rather than silently narrowing the feature.
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r): bool => $r->getName() === 'search.index');

    expect($route)->not->toBeNull()
        ->and(collect($route->gatherMiddleware())->filter(
            fn ($m): bool => is_string($m) && (str_starts_with($m, 'can:') || str_starts_with($m, 'feature:'))
        )->all())->toBe([]);
});
