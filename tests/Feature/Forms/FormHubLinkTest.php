<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Models\User;
use App\Support\Forms\FormHubLink;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment J2d — NAMING A FORM AND REACHING IT ARE DIFFERENT QUESTIONS.
|
| Six surfaces name forms through queries that are deliberately WIDER than the hub — the dashboard's
| top-forms bars, the analytics breakdown, the audit ledger's targets and the two integration Scope columns
| all resolve titles with `withTrashed()` on purpose, so a deleted form does not render as a bare uuid on
| the very row recording that it was deleted. `FormHubLink::pathsFor()` is the seam between the two
| questions, and this file is what makes "absent means unreachable" a fact rather than an intention.
|
| ⚠️ EVERY CASE HERE IS ABOUT AN ABSENT KEY, not a null value. The map omits ids it will not vouch for, with
| no branch distinguishing trashed from ungranted, so `?? null` at a call site is the whole guard and there
| is no third state for a caller to get wrong.
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
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('maps a live form an org-wide reader may open', function (): void {
    expect(FormHubLink::pathsFor($this->owner, [$this->form->id]))
        ->toBe([$this->form->id => '/forms/'.$this->form->id]);
});

it('spells the hub path exactly as the route does', function (): void {
    /*
     * The one string assertion in the file, and it is load-bearing: `path()` is the single spelling of
     * `/forms/{id}` in the application, so if it drifts, the strip, every crumb and every link in J2d drift
     * with it in step and no reachability test can see the difference — they would all navigate the same
     * wrong URL. Pinned against the ROUTE rather than against a literal for that reason.
     */
    expect(FormHubLink::path($this->form->id))
        ->toBe(parse_url(route('forms.show', $this->form, absolute: false), PHP_URL_PATH));
});

it('omits a soft-deleted form, which is the 404 this class exists to prevent', function (): void {
    /*
     * ⚠️ A GUARD FOR A STATE THE PRODUCT CANNOT CURRENTLY REACH, LABELLED AS ONE.
     *
     * The mechanism: `/forms/{form}` uses DEFAULT route-model binding — no `Route::bind`, no
     * `resolveRouteBinding()` override — so a trashed form 404s there, while `AuditLogPresenter` and
     * `DashboardMetricsService` resolve its TITLE on purpose.
     *
     * ⚠️ BUT NOTHING SOFT-DELETES A FORM TODAY: no delete route, no `$form->delete()` in `app/`, and
     * `FormService::archive()` only sets `status` + `archived_at`. An earlier version of this comment
     * claimed the audit ledger "would render `form.archived` rows as live hyperlinks to a 404" — false
     * twice over, since archiving is not deleting and an archived form's hub resolves 200.
     *
     * The case stays because `SoftDeletes` is on the model and a delete feature would otherwise reintroduce
     * the bug silently. It is a fail-closed guard, not a fix.
     */
    $this->form->delete();

    expect(FormHubLink::pathsFor($this->owner, [$this->form->id]))->toBe([]);
});

it('omits an id that no longer exists at all', function (): void {
    // A hard-deleted form (or a stale id carried in a shared URL) is absent by the same route as a trashed
    // one, deliberately — a caller must not be able to tell the two apart and branch on it.
    expect(FormHubLink::pathsFor($this->owner, ['00000000-0000-7000-8000-000000000000']))->toBe([]);
});

it('omits every form from a reader who lacks dashboard.form.view', function (): void {
    /*
     * `scopeReadableBy()`'s first conjunct, and the ONLY place in J2d where its user half is observable at
     * all: no shipped role can reach this state, because all five hold `dashboard.form.view`. It is a
     * fail-closed guard, pinned with a synthetic member rather than presented as a live rule — the standing
     * `FormPolicy::viewOverview()` and `FormTabSet` both carry for their own unobservable conjuncts.
     *
     * The synthetic-actor idiom is INLINED rather than borrowed from `ResourceGrantServiceTest::actorWith()`:
     * that is a file-local Pest function, so a single-file run of this test would die with "undefined
     * function" — the trap `tests/Pest.php` documents.
     */
    $stranger = User::factory()->create();
    makeActiveMember($stranger, 'viewer');
    $stranger->syncRoles([]);
    $stranger->syncPermissions(['dashboard.org.view', 'submissions.view']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(FormHubLink::pathsFor($stranger, [$this->form->id]))->toBe([]);
});

it('maps only the granted form for a reader without org-wide visibility', function (): void {
    /*
     * The `holdsAny` arm. A Form Editor holds `dashboard.form.view` and NOT `dashboard.org.view`, so the
     * scope narrows to their grants — and the grant is REVIEWER capacity here on purpose, mirroring
     * `scopeReadableBy()`'s own warning that an editor-capacity check would refuse the one role the scope
     * was widened for.
     */
    $other = publishedInboxForm($this->tenant, $this->owner, 'Someone Elses Form');

    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');
    makeCollaborator($this->form, $editor, ResourceCapacity::Reviewer);

    expect(FormHubLink::pathsFor($editor, [$this->form->id, $other->id]))
        ->toBe([$this->form->id => '/forms/'.$this->form->id]);
});

it('issues no query for an empty id list', function (): void {
    // A dashboard on a tenant with no forms at all calls this on every render. `whereIn('id', [])` is a
    // query worth not issuing, and the short-circuit is what makes the guard free rather than merely cheap.
    DB::enableQueryLog();
    DB::flushQueryLog();

    expect(FormHubLink::pathsFor($this->owner, []))->toBe([]);
    expect(DB::getQueryLog())->toBeEmpty();

    DB::disableQueryLog();
});

it('drops a chart bucket key instead of 500ing the page that carries one', function (): void {
    /*
     * ⚠️ THIS CASE FOUND A CRASH, WHICH IS WHY IT IS PHRASED AS A REFUSAL RATHER THAN AS TOLERANCE.
     *
     * Written to assert that aggregate buckets simply have no entry, it instead raised **SQLSTATE 22P02,
     * "invalid input syntax for type uuid: 'unassigned'"** — `forms.id` is a Postgres `uuid` column, so a
     * `whereIn` carrying a bucket key is a 500, not an empty result.
     *
     * ⚠️ NO LIVE CALLER PASSES SUCH A KEY, AND AN EARLIER VERSION OF THIS COMMENT CLAIMED ALL SIX WOULD.
     * `AnalyticsMetricsService::breakdown()` hoists both aggregates into SIBLING keys, so `rows` carries
     * only real uuids, and both chart call sites `array_filter` besides. This case pins the SEAM's contract
     * — a public helper six surfaces call must not be a 500 waiting for the first careless argument — and
     * not a bug any of them had.
     *
     * It is a Postgres-only failure — SQLite coerces the same query to a harmless no-match — so no amount of
     * reasoning about the array shape would have surfaced it, and a CI-only discovery would have read as a
     * dashboard outage. Process rule 4: verify against a real database.
     *
     * The duplicate id in the input is the second half: the breakdown builder can name one form twice across
     * periods, and the map must not depend on the caller de-duplicating first.
     */
    $paths = FormHubLink::pathsFor($this->owner, [$this->form->id, $this->form->id, 'unassigned', 'other']);

    expect($paths)->toBe([$this->form->id => '/forms/'.$this->form->id]);
});
