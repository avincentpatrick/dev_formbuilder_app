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
| Increment J2b — who may open the form hub at GET /forms/{form}.
|
| ⚠️ THIS FILE IS THE RECORD OF AN AUTHORIZATION WIDENING, so it asserts in BOTH directions: which roles
| the hub admits that `can:view,form` refuses, AND that `can:view,form` itself did not move. The second
| half is what `FormAnalyticsGateTest` passing UNEDITED proves — that file pins the Reviewer/Viewer
| refusals on /forms/{form}/analytics and must never need editing because of this one.
|
| The rule (user decision, 2026-08-11): `dashboard.form.view` AND (`dashboard.org.view` OR a grant on this
| form). It coins no permission key — the catalog stays at 29 — because it is byte-for-byte the split
| Submission::scopeVisibleTo() and AnalyticsFormSet::visible() already apply, and the one
| multi-tenancy-rbac-design.md §5 has documented against `dashboard.form.view` since Phase 0 ("✓ (own
| forms)" for Editor and Reviewer).
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

    $this->form = publishedInboxForm($this->tenant, $this->owner, 'Gated Form');
    seedCountableAt($this->form, CarbonImmutable::now()->subDay());
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function formHubUrl(string $formId, string $slug = 'acme'): string
{
    return 'http://'.$slug.'.meridian.test/forms/'.$formId;
}

/*
| ── The org-wide roles: everything, on the strength of `dashboard.org.view` ────────────────────────────
*/

it('lets an Owner in', function (): void {
    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(formHubUrl((string) $this->form->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('forms/Show', false)
            ->where('form.title', 'Gated Form')
            ->where('stats.responses', 1));
});

it('lets an Admin in for a form they never created', function (): void {
    $admin = User::factory()->create();
    makeActiveMember($admin, 'admin');
    app(ResourceGrantResolver::class)->forget();

    $this->withoutVite()
        ->actingAs($admin)
        ->get(formHubUrl((string) $this->form->id))
        ->assertOk();
});

it('lets a tenant VIEWER in — the widening, and the whole point of the increment', function (): void {
    // ⚠️ THE CASE THIS INCREMENT EXISTS FOR. A Viewer is refused by `can:view,form` (FormPolicy::view
    // delegates to canEdit), and is exactly who reads the audit ledger and the submission inbox — where a
    // form's TITLE was printed with nowhere to click. They hold `dashboard.org.view`, so no grant is
    // needed, and they already read every submission in the tenant: this hands them a destination, not a
    // fact they could not previously reach.
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');
    app(ResourceGrantResolver::class)->forget();

    $this->withoutVite()
        ->actingAs($viewer)
        ->get(formHubUrl((string) $this->form->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('form.title', 'Gated Form'));
});

/*
| ── The collaborator-scoped roles: their own forms only ────────────────────────────────────────────────
*/

it('lets a REVIEWER in for a form they hold a grant on', function (): void {
    // The second role the widening is for. A Reviewer's grant is REVIEWER capacity, which is why the policy
    // asks `holdsAny()` — an editor-capacity check would refuse the very role it was widened for. Mutated
    // below.
    $reviewer = User::factory()->create();
    makeActiveMember($reviewer, 'reviewer');
    makeCollaborator($this->form, $reviewer, ResourceCapacity::Reviewer);
    app(ResourceGrantResolver::class)->forget();

    $this->withoutVite()
        ->actingAs($reviewer)
        ->get(formHubUrl((string) $this->form->id))
        ->assertOk();
});

it('403s a Reviewer on a form they hold NO grant on', function (): void {
    // The other half. Without this the widening would read as "every member sees every form", which is not
    // what was decided and not what the rule says.
    $reviewer = User::factory()->create();
    makeActiveMember($reviewer, 'reviewer');
    app(ResourceGrantResolver::class)->forget();

    $this->withoutVite()
        ->actingAs($reviewer)
        ->get(formHubUrl((string) $this->form->id))
        ->assertForbidden();
});

/*
| ⚠️ ONE CASE PER ROLE-AND-GRANT COMBINATION, NEVER TWO REQUESTS IN ONE TEST, and the reason is not style.
| An HTTP request tears the tenant GUC down on its way out, so ANY write issued afterwards runs with no
| tenant context and RLS refuses it — `new row violates row-level security policy for table
| "resource_grants"`. `FormAnalyticsGateTest` records the same trap for `makeActiveMember()`; it bites
| `makeCollaborator()` identically. Two tests also name which half failed when one of them does.
*/

it('403s a Form Editor holding no grant on this form', function (): void {
    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');
    app(ResourceGrantResolver::class)->forget();

    $this->withoutVite()
        ->actingAs($editor)
        ->get(formHubUrl((string) $this->form->id))
        ->assertForbidden();
});

it('lets a Form Editor in with an editor grant', function (): void {
    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');
    makeCollaborator($this->form, $editor, ResourceCapacity::Editor);
    app(ResourceGrantResolver::class)->forget();

    $this->withoutVite()
        ->actingAs($editor)
        ->get(formHubUrl((string) $this->form->id))
        ->assertOk();
});

it('403s a member holding a grant but no read permission at all', function (): void {
    // ⚠️ THIS CASE EXISTS BECAUSE ITS MUTATION SURVIVED. Deleting the `dashboard.form.view` conjunct left
    // all twelve other cases in this file GREEN, and the docblock on `FormPolicy::viewOverview()` claimed
    // this file mutated it out — asserting a hypothesis as a measurement, the I11a sin, in the increment
    // that cites it. Both are now true.
    //
    // ⚠️ AND THE HONEST SCOPE, so nobody reads more into it than is there: NO SHIPPED ROLE CAN REACH THIS
    // STATE. All five hold `dashboard.form.view` (multi-tenancy-rbac-design.md §5), and the only product
    // path that changes a member's authority — `PATCH /members/{user}/role` on `tenant.roles.assign` —
    // assigns one of those five. The conjunct is therefore a FAIL-CLOSED STRUCTURAL GUARD rather than a
    // rule any user can observe today, and it earns its place because `resource_grants` is a CAPACITY
    // store, not a permission store: a grant says "editor capacity on this form", never "may read this
    // tenant's forms". Without the conjunct, the day a custom-role feature lands or a role drops that key,
    // the hub silently becomes readable on the strength of a grant alone.
    //
    // Built with the `actorWith()` idiom from `ResourceGrantServiceTest` — direct permissions on the
    // DEFAULT connection, never a minted global role on `pgsql_privileged`, which is outside
    // RefreshDatabase's transaction and leaked into a hundred later files when J1b tried it.
    $stranger = User::factory()->create();
    makeActiveMember($stranger, 'viewer');
    $stranger->syncRoles([]);
    $stranger->syncPermissions(['submissions.view']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    makeCollaborator($this->form, $stranger, ResourceCapacity::Editor);
    app(ResourceGrantResolver::class)->forget();

    $this->withoutVite()
        ->actingAs($stranger)
        ->get(formHubUrl((string) $this->form->id))
        ->assertForbidden();
});

/*
| ── The boundaries ─────────────────────────────────────────────────────────────────────────────────────
*/

it('404s a form belonging to another tenant', function (): void {
    // RLS at route-model binding: the form does not resolve at all, so not even its title crosses the
    // boundary. `inboxTenant()` rather than `Tenant::create()` because it also creates the DOMAIN row,
    // without which the host resolves to no tenant and the request is REDIRECTED before binding.
    $otherTenant = inboxTenant('globex');
    $stranger = User::factory()->create();

    enterTenant($otherTenant->id, $stranger->id);
    makeActiveMember($stranger, 'owner');

    $this->withoutVite()
        ->actingAs($stranger)
        ->get(formHubUrl((string) $this->form->id, 'globex'))
        ->assertNotFound();
});

it('still routes /forms/templates to the gallery, not to the hub', function (): void {
    // The H14 static-segment rule, asserted rather than assumed: a `{form}` pattern declared before
    // `/forms/templates` captures the literal word as a form id, and the failure is a 404 on a page that
    // has nothing to do with this increment.
    $this->withoutVite()
        ->actingAs($this->owner)
        ->get('http://acme.meridian.test/forms/templates')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('forms/Templates', false));
});

/*
| ── The tab set, which is the visible half of the same authorization ───────────────────────────────────
*/

it('offers an Owner every tab', function (): void {
    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(formHubUrl((string) $this->form->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tabs.0.key', 'overview')
            ->where('tabs.1.key', 'submissions')
            ->where('tabs.2.key', 'builder')
            ->where('tabs.3.key', 'analytics')
            ->has('tabs', 4)
            ->has('share'));
});

it('gives a Viewer the overview and responses only, with the refused tabs ABSENT', function (): void {
    // ADR-0011 §D9's absent-not-locked doctrine, and J1's "a refused arm is ABSENT, never
    // present-with-zero". A disabled Builder tab would be an upsell pointing at a capacity the role can
    // never hold.
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');
    app(ResourceGrantResolver::class)->forget();

    $this->withoutVite()
        ->actingAs($viewer)
        ->get(formHubUrl((string) $this->form->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tabs.0.key', 'overview')
            ->where('tabs.1.key', 'submissions')
            ->has('tabs', 2)
            // The share block carries the guest-access toggle and the rate limit — settings, not facts.
            ->missing('share')
            ->where('can.edit', false));
});

it('refuses the Analytics tab to a Reviewer, because can:view,form is unchanged by this increment', function (): void {
    // The proof the widening did not leak sideways. `/forms/{form}/analytics` still gates on
    // `can:view,form`, so the hub must not offer a Reviewer a tab that 403s on arrival — the exact defect
    // shape of a row action outliving the ability behind it.
    $reviewer = User::factory()->create();
    makeActiveMember($reviewer, 'reviewer');
    makeCollaborator($this->form, $reviewer, ResourceCapacity::Reviewer);
    app(ResourceGrantResolver::class)->forget();

    $response = $this->withoutVite()
        ->actingAs($reviewer)
        ->get(formHubUrl((string) $this->form->id))
        ->assertOk();

    $tabKeys = array_column($response->viewData('page')['props']['tabs'], 'key');

    expect($tabKeys)->not->toContain('analytics')
        ->and($tabKeys)->not->toContain('builder')
        ->and($tabKeys)->toContain('overview');

    // And the refusal is real, not merely un-offered.
    $this->withoutVite()
        ->actingAs($reviewer)
        ->get(formAnalyticsUrl((string) $this->form->id))
        ->assertForbidden();
});
