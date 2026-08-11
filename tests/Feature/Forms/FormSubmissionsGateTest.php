<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment J2c — who may open ONE FORM'S responses at GET /forms/{form}/submissions.
|
| ⚠️ THIS ROUTE CARRIES TWO GATES AND NEITHER ONE ALONE IS SUFFICIENT, which is the whole subject of this
| file. `can:viewAny,Submission` is the inbox's own gate and says nothing about WHICH form; `can:viewOverview,
| form` bounds the binding. With only the first, any member holding `submissions.view` — which is ALL FIVE
| seeded roles — could open this page for ANY form in the tenant and read its TITLE above an empty list.
| With only the second, a member who may open a form's hub but not read submissions would reach a responses
| list. The cases below drive each half out separately.
|
| Note what the page does NOT disclose even when refused: a cross-tenant form 404s at route-model binding
| under RLS, so its title never crosses the boundary at all.
|
| ⚠️ ONE HTTP REQUEST PER TEST, NEVER TWO — the `FormHubGateTest` rule and the same reason: a request tears
| the tenant GUC down on its way out, so any write issued afterwards runs with no tenant context and RLS
| refuses it ("new row violates row-level security policy for table \"resource_grants\"").
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

    $this->form = publishedInboxForm($this->tenant, $this->owner, 'Gated Responses');
    seedCountableAt($this->form, CarbonImmutable::now()->subDay());
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function formSubmissionsUrl(string $formId, string $slug = 'acme'): string
{
    // ⚠️ A distinct name from `FormHubGateTest`'s `formHubUrl()`: Pest helpers are GLOBAL, and a duplicate
    // definition fatals only when the whole suite runs, never on the single-file run that would catch it.
    return 'http://'.$slug.'.meridian.test/forms/'.$formId.'/submissions';
}

/*
| ── The org-wide roles ─────────────────────────────────────────────────────────────────────────────────
*/

it('lets an Owner in', function (): void {
    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(formSubmissionsUrl((string) $this->form->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('submissions/Inbox', false)
            ->where('form.title', 'Gated Responses')
            ->where('meta.total', 1));
});

it('lets a VIEWER in, which is the point of the second gate being viewOverview rather than view', function (): void {
    // A Viewer holds `submissions.view` and `dashboard.org.view` but no `forms.*` key at all, so
    // `can:view,form` — which delegates to canEdit — would refuse them. That is correct for the builder and
    // the analytics page and wrong here: a Viewer reading the audit ledger or a submission is exactly the
    // reader J2 exists to give a destination to. `FormTabSetReachabilityTest` drives this same URL as a
    // Viewer and requires a success, so this refusing would redden there too.
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');

    $this->withoutVite()
        ->actingAs($viewer)
        ->get(formSubmissionsUrl((string) $this->form->id))
        ->assertOk();
});

/*
| ── The collaborator-scoped roles: their own forms only ────────────────────────────────────────────────
*/

it('lets a REVIEWER in for a form they hold a grant on', function (): void {
    // `holdsAny()`, not `holds(Editor)` — a Reviewer's grant is REVIEWER capacity, so an editor-capacity
    // check would refuse the role this page most exists for.
    $reviewer = User::factory()->create();
    makeActiveMember($reviewer, 'reviewer');
    makeCollaborator($this->form, $reviewer, ResourceCapacity::Reviewer);
    app(ResourceGrantResolver::class)->forget();

    $this->withoutVite()
        ->actingAs($reviewer)
        ->get(formSubmissionsUrl((string) $this->form->id))
        ->assertOk();
});

it('403s a Reviewer on a form they hold NO grant on, rather than showing its title over an empty list', function (): void {
    // ⚠️ THE CASE THAT JUSTIFIES THE `viewOverview` GATE, and it is the one a single-gate route would fail.
    // A Reviewer holds `submissions.view`, so `can:viewAny,Submission` admits them; the row scope would then
    // return zero rows — but the page's h1, breadcrumb and tab strip all print the FORM'S TITLE, which they
    // hold no grant on. An empty list is not a refusal.
    $reviewer = User::factory()->create();
    makeActiveMember($reviewer, 'reviewer');
    app(ResourceGrantResolver::class)->forget();

    $this->withoutVite()
        ->actingAs($reviewer)
        ->get(formSubmissionsUrl((string) $this->form->id))
        ->assertForbidden();
});

it('403s a Form Editor holding no grant on this form', function (): void {
    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');
    app(ResourceGrantResolver::class)->forget();

    $this->withoutVite()
        ->actingAs($editor)
        ->get(formSubmissionsUrl((string) $this->form->id))
        ->assertForbidden();
});

it('lets a Form Editor in with an editor grant', function (): void {
    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');
    makeCollaborator($this->form, $editor, ResourceCapacity::Editor);
    app(ResourceGrantResolver::class)->forget();

    $this->withoutVite()
        ->actingAs($editor)
        ->get(formSubmissionsUrl((string) $this->form->id))
        ->assertOk();
});

/*
| ── The other half of the AND, driven out on its own ───────────────────────────────────────────────────
*/

it('403s a member who may open the form but holds no submissions.view', function (): void {
    // ⚠️ THE `can:viewAny,Submission` HALF. This member satisfies `viewOverview` outright — they hold
    // `dashboard.form.view` and `dashboard.org.view` — so a route gated on that alone would let them read a
    // responses list without the permission that governs responses.
    //
    // ⚠️ AND THE HONEST SCOPE: NO SHIPPED ROLE CAN REACH THIS STATE. All five hold `submissions.view`
    // (multi-tenancy-rbac-design.md §5), and the only product path that changes a member's authority
    // assigns one of those five, so this is a FAIL-CLOSED STRUCTURAL GUARD rather than a rule a user can
    // observe — the same standing this file's sibling gives `viewOverview`'s own `dashboard.form.view`
    // conjunct after its mutation survived. Built with `ResourceGrantServiceTest`'s `actorWith()` idiom:
    // direct permissions on the DEFAULT connection, never a minted global role on `pgsql_privileged`.
    $stranger = User::factory()->create();
    makeActiveMember($stranger, 'viewer');
    $stranger->syncRoles([]);
    $stranger->syncPermissions(['dashboard.form.view', 'dashboard.org.view']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->withoutVite()
        ->actingAs($stranger)
        ->get(formSubmissionsUrl((string) $this->form->id))
        ->assertForbidden();
});

it('403s a member holding a grant but neither read permission', function (): void {
    // The `viewOverview` half's own guard, mirroring `FormHubGateTest`: a grant is a CAPACITY, never a
    // permission, so it must not by itself open a page.
    $stranger = User::factory()->create();
    makeActiveMember($stranger, 'viewer');
    $stranger->syncRoles([]);
    $stranger->syncPermissions(['submissions.view']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    makeCollaborator($this->form, $stranger, ResourceCapacity::Editor);
    app(ResourceGrantResolver::class)->forget();

    $this->withoutVite()
        ->actingAs($stranger)
        ->get(formSubmissionsUrl((string) $this->form->id))
        ->assertForbidden();
});

/*
| ── The boundaries ─────────────────────────────────────────────────────────────────────────────────────
*/

it('404s a form belonging to another tenant', function (): void {
    // RLS at route-model binding: the form does not resolve, so not even its title crosses the boundary.
    // `inboxTenant()` rather than `Tenant::create()` because it also creates the DOMAIN row, without which
    // the host resolves to no tenant and the request is redirected before binding.
    $otherTenant = inboxTenant('globex');
    $stranger = User::factory()->create();
    enterTenant($otherTenant->id, $stranger->id);
    makeActiveMember($stranger, 'owner');
    $foreignForm = publishedInboxForm($otherTenant, $stranger, 'Not Yours');

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(formSubmissionsUrl((string) $foreignForm->id))
        ->assertNotFound();
});

it('still routes /forms/templates to the gallery rather than binding it as a form id', function (): void {
    // The H14 static-segment rule. This route adds a second `{form}`-prefixed URI, so the guard is worth
    // re-asserting where it can regress.
    $this->withoutVite()
        ->actingAs($this->owner)
        ->get('http://acme.meridian.test/forms/templates')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('forms/Templates', false));
});
