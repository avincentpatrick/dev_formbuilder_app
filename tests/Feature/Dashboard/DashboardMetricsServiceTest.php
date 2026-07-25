<?php

declare(strict_types=1);

use App\Enums\FormStatus;
use App\Enums\SubmissionStatus;
use App\Models\User;
use App\Services\Dashboard\DashboardMetricsService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| H11 — the dashboard KPI aggregator. Live COUNT-under-RLS, visibility-scoped: an org viewer
| (dashboard.org.view) gets tenant-wide totals + a Members count; a Form Editor/Reviewer gets
| own-forms counts and a null Members tile. Archived forms and draft submissions are excluded.
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

it('gives an org-wide viewer tenant-wide counts, excluding archived forms and draft submissions', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $formA = publishedInboxForm($tenant, $owner, 'Form A');
    $formB = publishedInboxForm($tenant, $owner, 'Form B');
    $retired = publishedInboxForm($tenant, $owner, 'Retired');
    $retired->update(['status' => FormStatus::Archived]); // excluded from the forms count

    seedInboxSubmission($formA, $owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);
    seedInboxSubmission($formB, $owner, SubmissionStatus::Submitted, ['full_name' => 'Grace']);
    seedInboxSubmission($formA, $owner, SubmissionStatus::Draft, ['full_name' => 'Partial']); // excluded

    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer'); // holds dashboard.org.view

    $kpis = app(DashboardMetricsService::class)->forUser($viewer);

    expect($kpis['forms'])->toBe(2)          // two active; the archived one excluded
        ->and($kpis['submissions'])->toBe(2) // two submitted; the draft excluded
        ->and($kpis['members'])->toBe(2);    // owner + viewer, both active
});

it('scopes a Form Editor to their own forms and withholds the Members count', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();

    $editor = User::factory()->create();
    enterTenant($tenant->id, $editor->id);
    $mine = publishedInboxForm($tenant, $editor, 'Mine'); // creator → editor grant
    makeActiveMember($editor, 'form_editor');

    enterTenant($tenant->id, $owner->id);
    $theirs = publishedInboxForm($tenant, $owner, 'Theirs'); // editor holds no grant
    seedInboxSubmission($mine, $editor, SubmissionStatus::Submitted, ['full_name' => 'Mine']);
    seedInboxSubmission($theirs, $owner, SubmissionStatus::Submitted, ['full_name' => 'Theirs']);
    seedInboxSubmission($mine, $editor, SubmissionStatus::Draft, ['full_name' => 'Partial']); // excluded

    enterTenant($tenant->id, $editor->id); // run the aggregator AS the editor
    $kpis = app(DashboardMetricsService::class)->forUser($editor);

    expect($kpis['forms'])->toBe(1)          // only 'Mine'
        ->and($kpis['submissions'])->toBe(1) // only Mine's submitted; 'Theirs' hidden, draft excluded
        ->and($kpis['members'])->toBeNull(); // no dashboard.org.view
});
