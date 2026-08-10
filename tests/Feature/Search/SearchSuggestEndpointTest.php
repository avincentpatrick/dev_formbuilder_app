<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| `GET /search/suggest` — the ⌘K palette's type-ahead (Increment J1d).
|
| ⚠️ THE ENDPOINT ADDS NO AUTHORIZATION SURFACE, AND THAT CLAIM IS WHAT THIS FILE CHECKS. It is
| `SearchPresenter::index()` with a smaller limit and a smaller envelope: same arms, same gates, same
| absent-not-zero rule. So the cases here are about the things that ARE new — that it answers JSON rather
| than Inertia, that it discloses no counts, that it is uncacheable, and that a refused arm is still absent.
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

    $this->reviewer = User::factory()->create();
    makeActiveMember($this->reviewer, 'reviewer');

    app(FormService::class)->create($this->tenant, $this->owner, 'Clinic Intake');

    $this->actingAs($this->owner);
});

it('answers JSON, not an Inertia response', function (): void {
    // ⚠️ THE CASE THAT WOULD CATCH THE WHOLE POINT OF THE ENDPOINT BEING UNDONE. If this ever starts
    // returning an Inertia payload, the palette silently begins re-dispatching the current page's
    // controller on every keystroke — which on the builder is a full builder render per debounce tick.
    $response = $this->getJson('http://acme.meridian.test/search/suggest?q=clinic');

    $response->assertOk()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonStructure(['q', 'groups', 'see_all_url']);

    expect($response->headers->get('x-inertia'))->toBeNull();
});

it('discloses no counts, because the palette does not need them', function (): void {
    // `hasMore` comes free from the arms' limit+1 overfetch; a real COUNT(*) per arm on every debounce
    // tick is the wrong shape, and omitting it keeps the count-disclosure question off the hot path.
    $response = $this->getJson('http://acme.meridian.test/search/suggest?q=clinic')->assertOk();

    expect($response->json())->not->toHaveKey('counts')
        ->and($response->json('groups.0'))->toHaveKeys(['entity', 'label', 'items', 'has_more']);
});

it('is never stored by a shared cache', function (): void {
    // The payload is permission-filtered per user. A shared cache holding one member's results and serving
    // them to another is exactly the disclosure the arms exist to prevent, arriving via the transport.
    // Asserted as PROPERTIES rather than as an exact string: Symfony normalises and reorders Cache-Control
    // directives, so pinning the literal value tests the framework's formatting rather than ours.
    $header = (string) $this->getJson('http://acme.meridian.test/search/suggest?q=clinic')
        ->assertOk()
        ->headers->get('cache-control');

    expect($header)->toContain('no-store')->and($header)->toContain('private');
});

it('omits an arm the caller may not use, rather than returning it empty', function (): void {
    // A Reviewer holds no `forms.*` key, so the forms arm is REFUSED — and a refused arm must be absent.
    // An empty group would itself disclose that a forms section exists and has nothing they may see.
    $entities = collect($this->actingAs($this->reviewer)->getJson('http://acme.meridian.test/search/suggest?q=clinic')->json('groups'))
        ->pluck('entity')
        ->all();

    expect($entities)->not->toContain('form');

    // Anti-vacuity: the owner DOES get a forms group for the same query, so "absent" above is the gate
    // working rather than the endpoint being broken.
    $ownerEntities = collect($this->actingAs($this->owner)->getJson('http://acme.meridian.test/search/suggest?q=clinic')->json('groups'))
        ->pluck('entity')
        ->all();

    expect($ownerEntities)->toContain('form');
});

it('answers an empty query with an empty list rather than an error', function (): void {
    $response = $this->getJson('http://acme.meridian.test/search/suggest?q=')->assertOk();

    expect($response->json('groups'))->toBe([]);
});

it('never validation-fails, however hostile the query', function (): void {
    // The same principle the page follows: every bound lives in the sanitiser and degrades, because a 422
    // on a GET this endpoint cannot even render is worse than a clamped result.
    foreach (['   ', 'foo &', '((', str_repeat('a', 1400)] as $q) {
        $this->getJson('http://acme.meridian.test/search/suggest?q='.urlencode($q))->assertOk();
    }

    // An array `q` is the one shape that must still be refused — it is not a longer string, it is a
    // different type, and `SearchTerms::parse()` would receive something it cannot clamp.
    $this->getJson('http://acme.meridian.test/search/suggest?q[]=x')->assertStatus(422);
});

it('requires authentication', function (): void {
    auth()->logout();

    $this->getJson('http://acme.meridian.test/search/suggest?q=clinic')->assertUnauthorized();
});

it('carries no can: or feature: gate, because authorization is per-arm', function (): void {
    $route = collect(app('router')->getRoutes())
        ->first(fn ($r): bool => $r->getName() === 'search.suggest');

    expect($route)->not->toBeNull();

    $middleware = $route->gatherMiddleware();
    $gates = array_filter($middleware, static fn ($m): bool => is_string($m)
        && (str_starts_with($m, 'can:') || str_starts_with($m, 'feature:')));

    expect($gates)->toBe([]);

    // But it IS throttled — the one thing that distinguishes it from the page route.
    expect(array_filter($middleware, static fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle:')))
        ->not->toBe([]);
});
