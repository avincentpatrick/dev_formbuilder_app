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
| Increment I10c — who may open /forms/{form}/analytics.
|
| `can:view,form` → FormPolicy::view → canEdit: `forms.edit.any`, or `forms.edit.own` plus an editor grant
| on THIS form. That is exactly docs/PRD.md:198's stated audience ("Form Owner/Editor view").
|
| A Reviewer and a tenant Viewer are REFUSED, and the cases below say so out loud rather than leaving it to
| be discovered. It is a decision, not an oversight: a Viewer's org-wide surface is /dashboard, a Reviewer's
| is the inbox, and both already answer "how many responses" for everything they can reach. Pinning the
| refusals is what makes a later "tidy-up" to can:viewAny,Submission or dashboard.form.view visible.
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

it('403s a Form Editor holding no grant on this form', function (): void {
    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');
    app(ResourceGrantResolver::class)->forget();

    $this->withoutVite()
        ->actingAs($editor)
        ->get(formAnalyticsUrl((string) $this->form->id))
        ->assertForbidden();
});

it('lets a Form Editor with an editor grant in', function (): void {
    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');
    makeCollaborator($this->form, $editor, ResourceCapacity::Editor);
    app(ResourceGrantResolver::class)->forget();

    $this->withoutVite()
        ->actingAs($editor)
        ->get(formAnalyticsUrl((string) $this->form->id))
        ->assertOk()
        // Not merely reachable — the grant must also make the rows countable, which is AnalyticsFormSet's
        // half of the job rather than the policy's.
        ->assertInertia(fn ($page) => $page->where('report.total.current', 1));
});

it('lets an Owner in for a form they never created', function (): void {
    $admin = User::factory()->create();
    makeActiveMember($admin, 'admin');
    app(ResourceGrantResolver::class)->forget();

    $this->withoutVite()
        ->actingAs($admin)
        ->get(formAnalyticsUrl((string) $this->form->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('report.total.current', 1));
});

// One case per role rather than a loop: the HTTP request tears down the tenant GUC on its way out, so a
// second `makeActiveMember()` in the same test inserts with no tenant context and RLS refuses it. Two tests
// also name which role failed when one of them does.
it('403s a Reviewer, which is a decision and not an oversight', function (): void {
    $reviewer = User::factory()->create();
    makeActiveMember($reviewer, 'reviewer');
    app(ResourceGrantResolver::class)->forget();

    // A Reviewer's surface is the inbox, which already answers "how many responses" for what they can reach.
    $this->withoutVite()
        ->actingAs($reviewer)
        ->get(formAnalyticsUrl((string) $this->form->id))
        ->assertForbidden();
});

it('403s a tenant Viewer, even though they hold dashboard.org.view', function (): void {
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');
    app(ResourceGrantResolver::class)->forget();

    // Deliberate asymmetry, recorded so it is not "tidied" later: a Viewer CAN read every org-wide KPI on
    // /dashboard, and is still refused here, because PRD.md:198 scopes this page to the Form Owner/Editor.
    // Widening it is an authorization change and wants a decision, not a refactor.
    $this->withoutVite()
        ->actingAs($viewer)
        ->get(formAnalyticsUrl((string) $this->form->id))
        ->assertForbidden();
});

it('404s a form belonging to another tenant', function (): void {
    // RLS at route-model binding: the form does not resolve at all, so nothing — not even its title —
    // crosses the tenant boundary. Zeroed aggregates would not have fixed an existence leak.
    // inboxTenant(), not Tenant::create(): it also creates the DOMAIN row, without which the host does not
    // resolve to a tenant at all and the request is REDIRECTED rather than reaching route-model binding.
    $otherTenant = inboxTenant('globex');
    $stranger = User::factory()->create();

    enterTenant($otherTenant->id, $stranger->id);
    makeActiveMember($stranger, 'owner');

    // Acme's form id, asked for on Globex's host: binding runs under Globex's RLS context and finds nothing,
    // so not even the TITLE crosses the boundary. Zeroed aggregates would not have fixed an existence leak.
    $this->withoutVite()
        ->actingAs($stranger)
        ->get(formAnalyticsUrl((string) $this->form->id, 'globex'))
        ->assertNotFound();
});
