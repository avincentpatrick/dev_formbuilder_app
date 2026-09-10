<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The WEB surface's JSON refusal (M90, docs/feature-backlog.md:8919).
|--------------------------------------------------------------------------
| `bootstrap/app.php` forks its refusal renderables on `$request->is('api/v1/*')` — a PATH test. Every
| sidecar `fetch` in this application (the builder, scopes, integrations, achievements) calls a tenant WEB
| route with `Accept: application/json`, so it missed that fork and fell to `back()`. `fetch` FOLLOWS a 302
| by default, the referer answers 200 HTML, `response.ok` is therefore TRUE, and the client throws a bare
| SyntaxError parsing HTML as JSON — so the tenant saw a generic message and was never told what was refused.
|
| ⛔ THE FLATNESS ARM IS THE LOAD-BEARING ONE, and it is the half the backlog row would have got wrong.
| The row prescribed reusing the /api/v1 arm, whose `ApiErrorResponse` nests everything under `error`.
| `builderClient` reads `payload.message` at the TOP level, so that fix would have returned a 402 the client
| still could not read and the user would still have seen "Request failed". A test that asserts only the
| STATUS passes against that half-fix; only asserting the shape of the body catches it.
|
| ⚠️ THE REDIRECT ARMS ARE REGRESSION ARMS, NOT DECORATION. A browser navigation and an Inertia visit must
| both keep the redirect-with-toast flow, and they do so for a stated reason rather than by luck: neither
| sends `Accept: application/json`, so `expectsJson()` is false for both. Remove that predicate and these
| two go red while the JSON arms stay green.
|
| Helpers are prefixed `webJsonRefusal*`: Pest loads the whole suite into one process, so a file-scope
| helper sharing a name with FeatureGateWebTest's `featureGateTenant()` is a fatal redeclaration.
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

/** An acme tenant with an active admin on `$tier`, context left on the tenant. */
function webJsonRefusalTenant(PlanTier $tier = PlanTier::Free): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    assignPlanTier($tier);

    return [$tenant, $admin];
}

it('answers a JSON fetch at a plan-gated web route with a readable JSON 402', function (): void {
    [, $admin] = webJsonRefusalTenant(PlanTier::Free);
    $form = app(FormService::class)->create(Tenant::query()->firstOrFail(), $admin, 'Survey');
    enterTenant($form->tenant_id, $admin->id);

    $response = $this->actingAs($admin)
        ->getJson("http://acme.meridian.test/forms/{$form->id}/library-items");

    $response->assertStatus(402)
        ->assertJsonPath('code', 'feature_not_available')
        ->assertJsonPath('details.feature', 'field_library');

    // ⛔ FLAT, NOT THE /api/v1 ENVELOPE. This is the assertion that fails against the fix the row
    // prescribed: `message` must be readable at the top level, and `error` must not be the wrapper.
    expect($response->json('message'))->toBeString()->not->toBe('');
    $response->assertJsonMissingPath('error');
});

it('still redirects a browser navigation at the same route, toast and all', function (): void {
    $this->withoutVite();
    [, $admin] = webJsonRefusalTenant(PlanTier::Free);
    $form = app(FormService::class)->create(Tenant::query()->firstOrFail(), $admin, 'Survey');
    enterTenant($form->tenant_id, $admin->id);

    // No Accept: application/json, so expectsJson() is false and the web arm is untouched. This is the
    // shipped behaviour FeatureGateWebTest pins; it is asserted here too so the JSON arm cannot be
    // widened later without one of these two going red.
    $this->actingAs($admin)
        ->get("http://acme.meridian.test/forms/{$form->id}/library-items")
        ->assertRedirect();
});

it('still redirects an INERTIA visit, because an Inertia visit does not expect JSON', function (): void {
    $this->withoutVite();
    [, $admin] = webJsonRefusalTenant(PlanTier::Free);
    $form = app(FormService::class)->create(Tenant::query()->firstOrFail(), $admin, 'Survey');
    enterTenant($form->tenant_id, $admin->id);

    // An Inertia visit sends X-Inertia with `Accept: text/html, application/xhtml+xml` — NOT
    // application/json — which is exactly why keying the new arm on expectsJson() cannot disturb any
    // Inertia flow. bootstrap/app.php's shouldRenderJsonWhen() comment states the same property.
    //
    // ⛔ THIS IS A **POST**, AND THAT IS THE WHOLE REASON IT IS ONE. Inertia's middleware compares the
    // asset version on a GET and returns 409 + X-Inertia-Location BEFORE calling $next, so an Inertia GET
    // with no matching version never reaches the route, never throws, and never touches the handler under
    // test. The first draft of this case was exactly that, and it PASSED — measuring the asset-version
    // protocol rather than the refusal. `Inertia::getVersion()` does not rescue it either: the version is
    // set by the middleware DURING the request, so it reads empty from a test that has not made one yet.
    // The version check does not apply to POST, so this reaches the gate for real.
    //
    // ⚠️ IT WAS THE MUTATION THAT CAUGHT THIS, NOT THE READING. Dropping the expectsJson() predicate turned
    // the browser-navigation arm red and left this one green; two arms that claim to test the same
    // predicate cannot disagree under a mutation of it, and that disagreement is what exposed the vacuity.
    $response = $this->actingAs($admin)
        ->withHeaders(['X-Inertia' => 'true', 'Accept' => 'text/html, application/xhtml+xml'])
        ->post("http://acme.meridian.test/forms/{$form->id}/fields/from-library", ['field_library_id' => 'x']);

    $response->assertRedirect();
    expect($response->status())->not->toBe(402);
});

it('leaves no exception renderable that redirects without a JSON sibling', function (): void {
    // ── THE COVERAGE RULE ────────────────────────────────────────────────────────────────────────────
    // The behavioural arms above prove two exception classes. This proves the OTHER TWELVE, and more to the
    // point it proves the thirteenth — the arm somebody adds next year. Without it this repair is a list of
    // hand-patched closures and the defect returns the first time one is written from the old template.
    //
    // ⛔ IT HAS A FLOOR, AND THE FLOOR IS THE POINT. This is a regex over PHP: the failure mode is not a
    // wrong answer but NO answer — a pattern that stops matching harvests zero arms, finds zero violations
    // and reports green while blind. The count below is asserted before the violations are, so a derivation
    // that breaks goes RED rather than quiet. It is a floor rather than an equality so that ADDING a
    // correctly-guarded arm does not redden it.
    //
    // ⚠️ MY OWN FIRST CENSUS OF THIS FILE WAS SHORT BY FIVE. `grep 'return back()'` found nine arms; five
    // more are written as a ternary (`: back()`) or as an arrow fn, and two of those took no Request
    // parameter at all. That is exactly why this reads the WHOLE closure rather than a statement shape.
    $source = file_get_contents(base_path('bootstrap/app.php'));
    expect($source)->toBeString()->not->toBe('');

    // Each `$exceptions->render(` opens one arm; an arm runs to the start of the next one.
    $starts = [];
    $offset = 0;
    while (($at = strpos((string) $source, '$exceptions->render(', $offset)) !== false) {
        $starts[] = $at;
        $offset = $at + 1;
    }

    expect(count($starts))->toBeGreaterThanOrEqual(25, 'the render()-arm census collapsed — the pattern stopped matching, which is a broken gate rather than a clean file');

    $redirecting = [];
    $unguarded = [];
    $uncaptured = [];

    foreach ($starts as $i => $start) {
        $end = $starts[$i + 1] ?? strlen((string) $source);
        $arm = substr((string) $source, $start, $end - $start);

        // The exception class is the first type in the closure signature — enough to name the offender.
        preg_match('/render\(\s*(?:function|fn)\s*\(([^,)]+)/', $arm, $m);
        $label = trim($m[1] ?? 'arm at offset '.$start);

        // ⛔ THE CAPTURE ARM, ADDED AFTER CI CAUGHT WHAT THIS GATE DID NOT. A `function` closure that
        // CALLS `$webJson(...)` without naming it in its `use (...)` is a PHP undefined-variable Error
        // at runtime — a 500 on the very refusal path this repair exists to fix. One arm shipped exactly
        // that: the body was rewritten and the `use` clause was not. The first version of this rule
        // asked only whether the arm mentioned `$webJson(`, which that arm did, so it passed. A gate
        // that checks the call and not the binding is checking the half that cannot fail alone.
        //
        // ⚠️ `fn` IS EXEMPT AND THAT IS THE LANGUAGE, NOT A CARVE-OUT. An arrow function captures by
        // value automatically and may not carry a `use` clause at all, so requiring one would redden
        // two correct arms. The first draft of THIS rule did exactly that — a gate written to catch an
        // over-narrow predicate, failing by being over-broad in the opposite direction.
        if (str_contains($arm, '$webJson(') && preg_match('/render\(\s*function\s*\(/', $arm) === 1) {
            preg_match('/\)\s*use\s*\(([^)]*)\)/', $arm, $useClause);

            if (! str_contains($useClause[1] ?? '', '$webJson')) {
                $uncaptured[] = $label;
            }
        }

        if (! str_contains($arm, 'back()->')) {
            continue;
        }

        $redirecting[] = $label;

        if (! str_contains($arm, '$webJson(')) {
            $unguarded[] = $label;
        }
    }

    expect(count($redirecting))->toBeGreaterThanOrEqual(14, 'the redirecting-arm census collapsed — see the floor note above');

    expect($unguarded)->toBe([], 'these exception renderables redirect with no $webJson sibling, so a JSON fetch reaching one gets HTML it cannot parse: '.implode(' · ', $unguarded));

    expect($uncaptured)->toBe([], 'these renderables CALL $webJson without capturing it in their use clause, which is an undefined-variable 500 on the refusal path: '.implode(' · ', $uncaptured));
});

it('answers a JSON fetch at a module-gated web route with a readable JSON refusal', function (): void {
    [$tenant, $admin] = webJsonRefusalTenant(PlanTier::Business);

    app(TenantSettingRegistry::class)->put($tenant, ['modules.gamification' => false], $admin);

    $response = $this->actingAs($admin)
        ->getJson('http://acme.meridian.test/achievements/streak');

    // A DIFFERENT exception class through the SAME closure, which is what makes this a shared repair
    // rather than one special case: ModuleDisabledException carries its own status and code.
    $response->assertStatus(403)->assertJsonPath('code', 'module_disabled');
    expect($response->json('message'))->toBeString()->not->toBe('');
    $response->assertJsonMissingPath('error');
});
