<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Support\Search\DestinationCatalog;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment J2d — EVERY DESTINATION THE PALETTE OFFERS MUST BE AN INERTIA PAGE.
|
| The debt `FormTabSetReachabilityTest` files by name: "a REAL request per URL rather than a string
| assertion, because 'the string looks like a route' is exactly what was true of the 404 above."
|
| ⚠️ AND A STRING ASSERTION IS PRECISELY WHAT LET THE DEFECT SHIP. `SearchDestinationArmTest` checks
| `expect($row['url'])->toStartWith('/')` — which `/notifications` passed, while being
| `NotificationController::index(): JsonResponse` with no Inertia page behind it at all. Both consumers hand
| a catalog url straight to the Inertia router (`CommandPalette.vue` `router.visit()`, `search/Index.vue`
| `<Link :href>`), so choosing it hard-navigated the user onto raw JSON. **No status code would have flagged
| it** — it was a 200 with the wrong content type, which is the more insidious variant of J2c's 405.
|
| ⚠️ THE ASSERTION IS `assertInertia()`, NOT `assertOk()`. `AssertableInertia::fromTestResponse()` fails with
| "Not a valid Inertia response" for a JSON body, a redirect or a download; a 200-tolerance passes all three.
| That is the entire difference between this file and the one that missed the bug.
|
| ⚠️ GATING IS DRIVEN FROM THE CATALOG, NEVER FROM THE ROUTES. `/domains` carries `feature => custom_domain`
| here while its route has no `feature:` middleware (ADR-0012 §D9, deliberate), so "hit every URL as an
| Owner" would test a different question from the one the product answers.
|
| ⚠️ ONE HTTP REQUEST PER CASE, hence datasets rather than loops — the tenant GUC is torn down on the way
| out (`FormHubGateTest`).
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // ⚠️ COMMITTED IDENTITIES, NOT `User::factory()` — `/members` is one of the destinations driven below,
    // and `TenantMembershipService` resolves identities on the separate `pgsql_auth` session, which cannot
    // see this transaction's uncommitted rows. See `committedTenantIdentity()` in tests/Pest.php: skipping
    // `/members` to avoid it would have been exactly the silent coverage cap this increment removes.
    $this->tenant = inboxTenant();
    $this->owner = committedTenantIdentity('Owner One');
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->viewer = committedTenantIdentity('Viewer Vee');
    makeActiveMember($this->viewer, 'viewer');

    // Enterprise carries every key in `PlanCatalog::FEATURE_KEYS`, so the feature-gated rows are actually
    // exercised rather than silently skipped. `forgetInstance` is required — `EntitlementService` caches the
    // resolved plan, the idiom `SearchDestinationArmTest` already uses.
    assignUnlimitedPlan();
    app()->forgetInstance(EntitlementService::class);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('offers an entitled Owner every destination in the catalog, and no more', function (): void {
    /*
     * This case does double duty, and both halves matter.
     *
     * It proves the FIXTURE unlocks every gate — so a destination missing from the dataset below is a red
     * test rather than an untested URL, which is the failure mode of a hand-written dataset beside an
     * authored list.
     *
     * And it is what reddens if anyone re-adds `notifications`, or adds a fourteenth row without a
     * reachability case. Compare `FormTabSetReachabilityTest`'s key-set case, which exists for exactly this.
     */
    $keys = array_column(app(DestinationCatalog::class)->visibleTo($this->owner), 'key');

    expect($keys)->toBe([
        'forms', 'submissions', 'dashboard', 'analytics', 'members', 'scopes',
        'audit', 'feedback', 'webhooks', 'integrations', 'domains', 'settings',
    ]);
});

it('lands an offered destination on a real Inertia page', function (string $key, string $component): void {
    /*
     * The body re-reads `visibleTo()` rather than trusting the dataset, so a request is only ever issued for
     * a destination the product actually OFFERS this reader — that is what "driven from the catalog" means
     * in practice, and it is why `/domains` is exercised here and would be skipped for an unentitled tenant.
     *
     * ⚠️ `assertOk()` IS ORDERED FIRST ON PURPOSE. `/integrations` returns `Response|RedirectResponse`,
     * 302ing when OAuth query params are present (they are not on the bare url). If that ever regresses to
     * always-redirect, failing on a legible status beats failing on "Not a valid Inertia response", which
     * reads as a routing bug and sends the next author to the wrong file.
     *
     * ⚠️ `->component($name, false)` — the second argument disables Inertia's page-file existence check, the
     * repo-wide convention for the same reason `withoutVite()` is: the Pest CI job builds no assets, so the
     * view finder cannot resolve a page and the assertion fails on Linux while passing on a
     * case-insensitive dev filesystem.
     */
    $row = collect(app(DestinationCatalog::class)->visibleTo($this->owner))->firstWhere('key', $key);

    expect($row)->not->toBeNull();

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get('http://acme.meridian.test'.$row['url'])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component($component, false));
})->with([
    'forms' => ['forms', 'forms/Index'],
    'submissions' => ['submissions', 'submissions/Inbox'],
    'dashboard' => ['dashboard', 'Dashboard'],
    'analytics' => ['analytics', 'analytics/Index'],
    'members' => ['members', 'members/Index'],
    'scopes' => ['scopes', 'scopes/Index'],
    'audit' => ['audit', 'audit/Index'],
    'feedback' => ['feedback', 'feedback/Index'],
    'webhooks' => ['webhooks', 'webhooks/Index'],
    'integrations' => ['integrations', 'integrations/Index'],
    'domains' => ['domains', 'domains/Index'],
    'settings' => ['settings', 'Settings/Index'],
]);

it('offers a Viewer only the destinations a Viewer can open', function (): void {
    // The absent half of the contract, and the reason the catalog filters at all. A Viewer holds
    // `submissions.view` plus both dashboard keys, so the analytics gate passes and the Enterprise plan
    // supplies its feature; every management row drops out.
    $keys = array_column(app(DestinationCatalog::class)->visibleTo($this->viewer), 'key');

    expect($keys)->toBe(['submissions', 'dashboard', 'analytics', 'settings']);
});

it('lands a Viewer on a real Inertia page too', function (string $key, string $component): void {
    // The J2b/J2c bug class, one surface over: a destination that resolves for an Owner and 403s for the
    // role actually being offered it. Driving the narrowest reader is what makes the offer honest.
    $row = collect(app(DestinationCatalog::class)->visibleTo($this->viewer))->firstWhere('key', $key);

    expect($row)->not->toBeNull();

    $this->withoutVite()
        ->actingAs($this->viewer)
        ->get('http://acme.meridian.test'.$row['url'])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component($component, false));
})->with([
    'submissions' => ['submissions', 'submissions/Inbox'],
    'dashboard' => ['dashboard', 'Dashboard'],
    'analytics' => ['analytics', 'analytics/Index'],
    'settings' => ['settings', 'Settings/Index'],
]);

it('offers no destination whose url answers with JSON rather than a page', function (): void {
    /*
     * The rule stated directly, so it survives someone deleting the dataset above and so the NEXT
     * JSON-endpoint row is refused by name rather than by a case nobody thought to add.
     *
     * `/notifications` is the one that shipped: `routes/tenant.php` says "a BELL, not a page. There is
     * deliberately no /notifications Inertia page", and all three of its routes return JSON.
     */
    $urls = array_column(app(DestinationCatalog::class)->visibleTo($this->owner), 'url');

    expect($urls)->not->toContain('/notifications');
});
