<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Forms\FormTabSet;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment J2b — EVERY TAB THE STRIP OFFERS MUST LEAD SOMEWHERE.
|
| ⚠️ THIS IS A STRUCTURAL PIN, AND IT EXISTS BECAUSE THE INCREMENT ALMOST SHIPPED THE DEFECT IT PREVENTS.
| The Responses tab was written as `/forms/{form}/submissions` — the route J2c builds and that does not
| exist yet — so the strip's second item would have 404'd on the very page opened to remove dead ends. It
| now points at the filtered inbox, and this file is what makes that a fact rather than an intention: a tab
| whose href does not resolve to a reachable route fails here, whatever a future author points it at.
|
| The shape is the one J2d owes for `DestinationCatalog` — a REAL request per URL rather than a string
| assertion, because "the string looks like a route" is exactly what was true of the 404 above.
|
| ⚠️ ONE HTTP REQUEST PER TEST, hence a dataset rather than a loop. `FormHubGateTest` records why: the
| request tears the tenant GUC down on the way out, so a second one in the same test runs without tenant
| context. A `foreach` over four URLs inside one `it()` passes for the first and misleads for the rest.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->form = publishedInboxForm($this->tenant, $this->owner, 'Reachable Form');
    seedCountableAt($this->form, CarbonImmutable::now()->subDay());
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('offers an Owner the four tabs, and no more', function (): void {
    // The dataset below names its URLs by tab key, so it is only exhaustive if the key set is what it
    // assumes. This case is what keeps the two in step: add a fifth tab and it reddens here, which is the
    // prompt to add its reachability row rather than discovering the omission in the browser.
    $keys = array_column(FormTabSet::for($this->form, $this->owner), 'key');

    expect($keys)->toBe(['overview', 'submissions', 'builder', 'analytics']);
});

it('offers a tab whose href resolves to a real page', function (string $key): void {
    /*
     * A GET as the reader who was offered the tab. `assertSuccessful()` rather than a 200-or-redirect
     * tolerance on purpose: a tab that bounces its reader somewhere else is still a broken destination, and
     * the two failures this catches — 404 (route absent) and 403 (offered to someone the route refuses) —
     * are the two ways a strip lies.
     */
    $tab = collect(FormTabSet::for($this->form, $this->owner))->firstWhere('key', $key);

    expect($tab)->not->toBeNull();

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get('http://acme.meridian.test'.$tab['href'])
        ->assertSuccessful();
})->with(['overview', 'submissions', 'builder', 'analytics']);

it('points Responses at a destination that survives a form with no responses at all', function (): void {
    /*
     * ⚠️ THE CASE THAT JUSTIFIES THE CHOSEN HREF, and it is a measurement rather than an argument.
     *
     * The inbox's own form dropdown is derived from SUBMISSIONS (`SubmissionInboxPresenter::formOptions()`),
     * so a brand-new form is not among its options — which is what makes "just link to the filtered inbox"
     * look unsafe. It is safe, and this proves it: the filter is read as a plain query string and composed
     * as a bare `where`, with no `Rule::in` anywhere, so filtering by a form with zero rows yields an empty
     * list rather than a 422. (Fixing the dropdown itself is J2c's.)
     */
    $empty = publishedInboxForm($this->tenant, $this->owner, 'Nobody Has Answered This');

    $tab = collect(FormTabSet::for($empty, $this->owner))->firstWhere('key', 'submissions');

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get('http://acme.meridian.test'.$tab['href'])
        ->assertSuccessful();
});

it('omits the tabs a Viewer cannot reach rather than offering them', function (): void {
    // The absent half of the same contract: `FormTabSet` must not emit a row it would then have to
    // apologise for. A Viewer holds `submissions.view` and `dashboard.org.view` but no `forms.edit.*`, so
    // the builder and the analytics page — both keyed on abilities that delegate to canEdit — drop out.
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');

    $keys = array_column(FormTabSet::for($this->form, $viewer), 'key');

    expect($keys)->toBe(['overview', 'submissions']);
});
