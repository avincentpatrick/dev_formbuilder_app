<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment B2a — RBAC row-level-security fuzz pack. Runs as `meridian_app`.
|--------------------------------------------------------------------------
| Proves the two Spatie RLS shapes at the DATABASE layer (raw DB::, never the Spatie/ORM convenience
| layer, so what is proven is Postgres enforcement): the assignment pivot `model_has_roles` is strictly
| tenant-isolated, and the global `roles`/`permissions` catalog is readable by every tenant yet writable
| by none of them. As in Increment A, because `meridian_app` OWNS these tables, each isolation assertion
| is simultaneously the proof that FORCE ROW LEVEL SECURITY is active.
|
| The catalog is seeded on the privileged connection (its NULL-tenant rows are forbidden to meridian_app
| by the nullable-global write policy), committed outside RefreshDatabase's transaction. It is
| deliberately NOT cleaned up afterwards: the seeder is idempotent (matched on name), so re-running is a
| no-op, and — critically — DELETEing a parent `roles` row from the privileged connection would DEADLOCK
| against the uncommitted `model_has_roles` child rows a test leaves in its own (not-yet-rolled-back)
| meridian_app transaction. Global reference data is a legitimate shared fixture; migrate:fresh wipes it
| at the start of the next suite run.
*/

beforeEach(function (): void {
    TenantContext::flush();
    (new RolePermissionSeeder)->run(); // idempotent; committed on the privileged connection
});

it('has RLS enabled AND forced on the role tables', function (): void {
    foreach (['roles', 'permissions', 'model_has_roles', 'model_has_permissions'] as $table) {
        $meta = DB::selectOne(
            'select relrowsecurity::int as enabled, relforcerowsecurity::int as forced '
            .'from pg_class where relname = ?',
            [$table]
        );

        expect((int) $meta->enabled)->toBe(1); // ENABLE
        expect((int) $meta->forced)->toBe(1);  // FORCE — applies to the owning app role
    }
});

it('reveals the global catalog to every tenant, but lets no tenant author a global role', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);

    TenantContext::applyLocal($a->id);

    // The nullable-global SELECT policy reveals the platform catalog to every tenant …
    // Derived from the seeder rather than hardcoded: the catalog grew to 28 in G10a (`scopes.manage`),
    // and a literal here just means the next addition fails a test about RLS for reasons unrelated to RLS.
    expect(DB::table('roles')->count())->toBe(count(RolePermissionSeeder::ROLES));
    expect(DB::table('permissions')->count())->toBe(count(RolePermissionSeeder::PERMISSIONS));

    // … but the write policy stays strict: a tenant-scoped connection can never mint a global row.
    expect(fn () => DB::table('roles')->insert([
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => null,
        'name' => 'superuser',
        'guard_name' => 'web',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('strictly isolates model_has_roles assignments by tenant', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $b = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo']);
    $user = User::factory()->create();
    $adminRoleId = DB::connection('pgsql_privileged')->table('roles')->where('name', 'admin')->value('id');

    // Assign under A's context — the strict INSERT policy requires tenant_id = app.current_tenant_id.
    TenantContext::applyLocal($a->id);
    DB::table('model_has_roles')->insert([
        'role_id' => $adminRoleId,
        'model_type' => User::class,
        'model_id' => $user->id,
        'tenant_id' => $a->id,
    ]);

    // Visible under A, invisible under B.
    TenantContext::applyLocal($a->id);
    expect(DB::table('model_has_roles')->count())->toBe(1);
    TenantContext::applyLocal($b->id);
    expect(DB::table('model_has_roles')->count())->toBe(0);

    // A cannot forge an assignment scoped to B (WITH CHECK).
    TenantContext::applyLocal($a->id);
    expect(fn () => DB::table('model_has_roles')->insert([
        'role_id' => $adminRoleId,
        'model_type' => User::class,
        'model_id' => $user->id,
        'tenant_id' => $b->id,
    ]))->toThrow(QueryException::class);
});
