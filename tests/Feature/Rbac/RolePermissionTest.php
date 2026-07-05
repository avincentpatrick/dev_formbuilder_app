<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment B2a — RBAC role resolution through Spatie (the app layer).
|--------------------------------------------------------------------------
| Where RbacRlsTest proves the DATABASE isolates the pivots, this proves the APPLICATION resolves the
| right permissions: a role's grants resolve against the active tenant team, a multi-tenant user gets
| different permissions per tenant, and — the hazard the middleware guards against — a request that set
| RLS tenant context but forgot Spatie's permissions team fails EVERY check silently (fail-closed, but
| invisible). Both the RLS session context and the Spatie team must be set together; actAsTenant() does.
*/

/** Set BOTH the RLS session context and Spatie's permissions team to the same tenant (as the middleware does). */
function actAsTenant(string $tenantId, string $userId): void
{
    TenantContext::applyLocal($tenantId, $userId);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
}

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run(); // idempotent, committed on the privileged connection
    app(PermissionRegistrar::class)->forgetCachedPermissions(); // reload the freshly-seeded catalog
});

afterEach(function (): void {
    // Reset only the in-process Spatie team id. The global catalog is intentionally NOT deleted: it is
    // idempotent shared reference data, and DELETEing a parent `roles` row from the privileged
    // connection would deadlock against the uncommitted `model_has_roles` rows assignRole() leaves in
    // the test's own meridian_app transaction (not rolled back until after this hook). See RbacRlsTest.
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('resolves a role\'s permissions against the active tenant', function (): void {
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $user = User::factory()->create();

    actAsTenant($tenant->id, $user->id);
    $user->assignRole('admin');

    expect($user->hasPermissionTo('forms.edit.any'))->toBeTrue();
    expect($user->hasPermissionTo('submissions.review.any'))->toBeTrue();
    // Admin lacks the two Owner-only capabilities (proves the matrix, not just "has any permission").
    expect($user->hasPermissionTo('tenant.ownership.transfer'))->toBeFalse();
    expect($user->hasPermissionTo('tenant.billing.manage'))->toBeFalse();
});

it('fails closed when tenant context is set but the permissions team is not', function (): void {
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $user = User::factory()->create();

    actAsTenant($tenant->id, $user->id);
    $user->assignRole('admin');
    expect($user->hasPermissionTo('forms.edit.any'))->toBeTrue(); // sanity: works with the team set

    // The exact hazard the middleware guards against: RLS context still set, the team forgotten.
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    expect($user->hasPermissionTo('forms.edit.any'))->toBeFalse();
});

it('resolves different permissions per tenant for a multi-tenant user', function (): void {
    $tenantA = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $tenantB = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo']);
    $user = User::factory()->create();

    // Admin in A …
    actAsTenant($tenantA->id, $user->id);
    $user->assignRole('admin');

    // … Viewer in B.
    actAsTenant($tenantB->id, $user->id);
    $user->unsetRelation('roles');
    $user->assignRole('viewer');

    // In A the user is an admin.
    actAsTenant($tenantA->id, $user->id);
    $user->unsetRelation('roles');
    expect($user->hasPermissionTo('forms.edit.any'))->toBeTrue();

    // In B only a viewer: no form editing, but org dashboard is allowed.
    actAsTenant($tenantB->id, $user->id);
    $user->unsetRelation('roles');
    expect($user->hasPermissionTo('forms.edit.any'))->toBeFalse();
    expect($user->hasPermissionTo('dashboard.org.view'))->toBeTrue();
});
