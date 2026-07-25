<?php

declare(strict_types=1);

use App\Enums\SubmissionStatus;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| H11 — the dashboard page renders real KPI props from DashboardController → DashboardMetricsService,
| visibility-scoped (org-wide vs own-forms) with the Members tile withheld from form-scoped roles.
|--------------------------------------------------------------------------
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

it('renders org-wide KPI props for a Viewer', function (): void {
    $this->withoutVite();
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $formA = publishedInboxForm($tenant, $owner, 'Form A');
    $formB = publishedInboxForm($tenant, $owner, 'Form B');
    seedInboxSubmission($formA, $owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);
    seedInboxSubmission($formB, $owner, SubmissionStatus::Submitted, ['full_name' => 'Grace']);

    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard', false)
            ->where('kpis.forms', 2)
            ->where('kpis.submissions', 2)
            ->where('kpis.members', 2));
});

it('omits the Members KPI (null) for a form-scoped role', function (): void {
    $this->withoutVite();
    $tenant = inboxTenant();
    $editor = User::factory()->create();
    enterTenant($tenant->id, $editor->id);
    $mine = publishedInboxForm($tenant, $editor, 'Mine');
    makeActiveMember($editor, 'form_editor');
    seedInboxSubmission($mine, $editor, SubmissionStatus::Submitted, ['full_name' => 'Mine']);

    $this->actingAs($editor)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard', false)
            ->where('kpis.forms', 1)
            ->where('kpis.submissions', 1)
            ->where('kpis.members', null));
});
