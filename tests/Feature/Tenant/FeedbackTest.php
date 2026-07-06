<?php

declare(strict_types=1);

use App\Models\FeedbackReport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment C3 — in-app feedback (PRD Feature #11).
|--------------------------------------------------------------------------
| Proves the submit path: any role may submit (can:feedback.submit is granted to all), the row is
| written under the tenant's RLS context (tenant_id filled by BelongsToTenant, user_id from the actor),
| remarks are required, and a report is strictly tenant-scoped (invisible from another tenant's context).
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

it('lets any role submit feedback, scoped to the tenant', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'viewer'); // the least-privileged role still holds feedback.submit

    $this->actingAs($user)
        ->post('http://acme.meridian.test/feedback', [
            'route' => '/dashboard',
            'remarks' => 'The dashboard is great, but exports are slow.',
            'browser_info' => ['userAgent' => 'PestUA', 'viewport' => '1440x900'],
        ])
        ->assertRedirect();

    enterTenant($tenant->id, $user->id);
    $report = FeedbackReport::query()->firstOrFail();
    expect($report->tenant_id)->toBe($tenant->id)
        ->and($report->user_id)->toBe($user->id)
        ->and($report->remarks)->toBe('The dashboard is great, but exports are slow.')
        ->and($report->status->value)->toBe('new')
        ->and($report->browser_info)->toMatchArray(['userAgent' => 'PestUA']);
});

it('requires remarks', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'viewer');

    $this->actingAs($user)
        ->post('http://acme.meridian.test/feedback', ['route' => '/dashboard'])
        ->assertSessionHasErrors('remarks');

    enterTenant($tenant->id, $user->id);
    expect(FeedbackReport::query()->count())->toBe(0);
});

it('isolates feedback to its own tenant under RLS', function (): void {
    $tenantA = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenantA->domains()->create(['domain' => 'acme']);
    $userA = User::factory()->create();
    enterTenant($tenantA->id, $userA->id);
    makeActiveMember($userA, 'viewer');

    $this->actingAs($userA)->post('http://acme.meridian.test/feedback', [
        'route' => '/dashboard',
        'remarks' => 'Tenant A confidential note.',
    ])->assertRedirect();

    // A second tenant's context sees none of tenant A's reports.
    $tenantB = Tenant::create(['name' => 'Globex', 'slug' => 'globex']);
    $userB = User::factory()->create();
    enterTenant($tenantB->id, $userB->id);
    makeActiveMember($userB, 'viewer');

    expect(FeedbackReport::query()->count())->toBe(0);

    // …while tenant A still sees its own.
    enterTenant($tenantA->id, $userA->id);
    expect(FeedbackReport::query()->count())->toBe(1);
});
