<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormBotChallenge;
use App\Enums\RequiredMode;
use App\Http\Middleware\AppSecurityHeaders;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment I1 — baseline security headers for the authenticated surfaces.
|--------------------------------------------------------------------------
| I1 had to decide `frame-ancestors` for the public runtime (PRD Feature #3 requires third-party embedding,
| so: `*`). Deciding it there and leaving the surface that actually holds a session undecided would have been
| answering the easier half, so the admin app gets the inverse: `frame-ancestors 'none'`.
|
| THE LOAD-BEARING TEST IN THIS FILE IS THE LAST ONE. PublicRuntimeSecurityHeaders is also mounted on
| GET /forms/{form}/submissions/create — authenticated (so AppSecurityHeaders applies) and map-bearing (so
| the OSM img-src allowlist applies). That middleware refuses to clobber an existing CSP, so if this one ever
| WROTE the header first, the tile allowlist would never be emitted and the map would silently go blank on
| one page, with nothing in any log. The merge makes that safe in either order; this pins it.
*/

// ── Unit-level: the merge itself ─────────────────────────────────────────────────────────────

it('sets frame-ancestors none plus the baseline headers', function (): void {
    $middleware = new AppSecurityHeaders;

    $response = $middleware->handle(
        Request::create('/dashboard'),
        fn (): Response => new Response('ok'),
    );

    expect($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'none'")
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
});

it('MERGES into a policy another layer already set rather than replacing it', function (): void {
    $middleware = new AppSecurityHeaders;

    $response = $middleware->handle(
        Request::create('/forms/x/submissions/create'),
        function (): Response {
            $response = new Response('ok');
            $response->headers->set('Content-Security-Policy', "img-src 'self' https://tile.example");

            return $response;
        },
    );

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("img-src 'self' https://tile.example")
        ->and($csp)->toContain("frame-ancestors 'none'");
});

it('REPLACES a laxer frame-ancestors inherited from another surface', function (): void {
    // This test found a real hole in the first draft, which deferred to an existing frame-ancestors. The
    // only middleware that sets one is PublicRuntimeSecurityHeaders, whose `*` is meant for the GUEST
    // runtime — but it is also mounted on the authenticated manual-encode page for its map allowlist. So
    // deferring handed "anyone may frame this" to a logged-in page. On a surface with a session, 'none'
    // wins; the old directive is dropped rather than appended to, since two of them is undefined behaviour.
    $middleware = new AppSecurityHeaders;

    $response = $middleware->handle(
        Request::create('/forms/x/submissions/create'),
        function (): Response {
            $response = new Response('ok');
            $response->headers->set('Content-Security-Policy', "img-src 'self'; frame-ancestors *");

            return $response;
        },
    );

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->toBe("img-src 'self'; frame-ancestors 'none'")
        ->and($csp)->not->toContain('frame-ancestors *');
});

it('does not introduce default-src', function (): void {
    // The piping-design §262 tripwire, restated on this side: introducing default-src forces every other
    // directive to be enumerated in the same change, and would break the inline Inertia bootstrap.
    $middleware = new AppSecurityHeaders;

    $response = $middleware->handle(
        Request::create('/dashboard'),
        fn (): Response => new Response('ok'),
    );

    expect($response->headers->get('Content-Security-Policy'))->not->toContain('default-src');
});

// ── Route-level: what actually lands on real responses ───────────────────────────────────────

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('refuses framing on an authenticated page', function (): void {
    $response = $this->actingAs($this->admin)->withoutVite()
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY');
});

it('does NOT refuse framing on the guest runtime', function (): void {
    // The whole product depends on this negative: a global mount on `web` would land here and break every
    // embed. The guest group is deliberately not in AppSecurityHeaders' four mount points.
    $tenant = $this->tenant;
    $owner = $this->admin;

    $form = app(FormService::class)->create($tenant, $owner, 'Intake');
    addFormField($form->draftVersion, $owner, 'full_name', FieldType::ShortText, 0, [
        'is_required' => RequiredMode::Required,
    ]);
    app(PublishService::class)->publish($form->refresh(), $owner);
    app(FormService::class)->setShareSettings($form->refresh(), 'intake', true, FormBotChallenge::Off, null, $owner);

    $response = $this->withoutVite()->get('http://acme.meridian.test/f/intake')->assertOk();

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain('frame-ancestors *')
        ->and($csp)->not->toContain("frame-ancestors 'none'")
        ->and($response->headers->get('X-Frame-Options'))->toBeNull();
});

it('keeps BOTH policies on the one route that carries both middlewares', function (): void {
    // GET /forms/{form}/submissions/create is authenticated AND map-bearing. If the merge ever regressed to
    // a plain set, the OSM allowlist would vanish and the Leaflet map would render with no tiles — a defect
    // no error surfaces and no other test in the suite would see.
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Intake');
    addFormField($form->draftVersion, $this->admin, 'full_name', FieldType::ShortText, 0);
    app(PublishService::class)->publish($form->refresh(), $this->admin);

    $response = $this->actingAs($this->admin)->withoutVite()
        ->get("http://acme.meridian.test/forms/{$form->id}/submissions/create")
        ->assertOk();

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain('https://*.tile.openstreetmap.org')
        ->and($csp)->toContain("worker-src 'self'")
        ->and($csp)->toContain("frame-ancestors 'none'");
});
