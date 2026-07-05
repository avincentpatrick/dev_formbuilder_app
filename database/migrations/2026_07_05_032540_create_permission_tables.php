<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spatie Laravel-Permission tables — Increment B2a (multi-tenancy-rbac-design.md §4).
 *
 * This is a heavily customized rewrite of Spatie's published `create_permission_tables` stub. Two
 * project-wide deviations from the stock migration, both deliberate:
 *
 *   1. UUIDv7 primary keys on `roles`/`permissions` (stock uses auto-increment bigint) and UUID
 *      morph/pivot keys throughout, so these tables follow the schema-wide PK strategy and roles stay
 *      non-enumerable on the forward-referenced GET /api/v1/roles.
 *   2. The team foreign key is named `tenant_id` (config override, not stock `team_id`) and — critically
 *      — RLS is attached via {@see TenantIsolation}. Stock Spatie has no awareness of this project's
 *      RLS backstop; without these calls the role pivots would ship with NO row-level isolation. The
 *      column is written as a LITERAL here (not a config variable as in the stub) so the migration
 *      linter's generic `tenant_id`-without-isolation check can see it, and scripts/migration-lint.php
 *      additionally enforces a Spatie-pivot-specific rule (RBAC §4's flagged CI gap).
 *
 * RLS shapes:
 *   - roles / permissions      → nullable-global. The fixed catalog is seeded once as global rows
 *                                (tenant_id IS NULL) by RolePermissionSeeder on the privileged
 *                                connection; every tenant may READ the global catalog, but no
 *                                tenant-scoped app connection can author a global row.
 *   - model_has_roles /        → strict. The actual per-tenant role assignment, keyed on
 *     model_has_permissions       app.current_tenant_id (== Spatie's permissions team id == the
 *                                 subdomain tenant — all one value, set together in middleware).
 *   - role_has_permissions     → no tenant dimension at all (global-to-global map); left un-isolated,
 *                                on ADR-0002 §D1's exemption list alongside `plans`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // permissions — global, code-controlled vocabulary. tenant_id is always NULL (never team-scoped
        // in Phase 1); present so the nullable-global write policy protects the catalog from tenant writes.
        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(); // always NULL — global catalog
            $table->index('tenant_id', 'permissions_tenant_id_index');
            $table->string('name', 100);
            $table->string('guard_name', 50);
            $table->timestampsTz();

            $table->unique(['name', 'guard_name']);
        });

        // roles — global, platform-seeded 5-role catalog. tenant_id always NULL (see §3 design note).
        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(); // team key — always NULL (global catalog)
            $table->index('tenant_id', 'roles_tenant_id_index');
            $table->string('name', 50);
            $table->string('guard_name', 50);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'name', 'guard_name']);
        });

        // model_has_permissions — present because Spatie ships it, but UNUSED in Phase 1 (every
        // authorization flows through roles, for auditability). Strict tenant scope regardless.
        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->uuid('permission_id');
            $table->string('model_type');
            $table->uuid('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->uuid('tenant_id');
            $table->index('tenant_id', 'model_has_permissions_tenant_id_index');

            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->primary(
                ['tenant_id', 'permission_id', 'model_id', 'model_type'],
                'model_has_permissions_permission_model_type_primary'
            );
        });

        // model_has_roles — the actual per-(tenant, user) role grant. Strictly tenant-scoped.
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->uuid('role_id');
            $table->string('model_type');
            $table->uuid('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->uuid('tenant_id');
            $table->index('tenant_id', 'model_has_roles_tenant_id_index');

            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->primary(
                ['tenant_id', 'role_id', 'model_id', 'model_type'],
                'model_has_roles_role_model_type_primary'
            );
        });

        // role_has_permissions — global-to-global mapping, no tenant dimension (ADR-0002 §D1 exempt).
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->uuid('permission_id');
            $table->uuid('role_id');

            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();

            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });

        // Attach RLS. roles/permissions = nullable-global (catalog readable by all, writable by none
        // but the privileged seeder); the assignment pivots = strict tenant equality.
        TenantIsolation::nullableGlobal('permissions');
        TenantIsolation::nullableGlobal('roles');
        TenantIsolation::strict('model_has_permissions');
        TenantIsolation::strict('model_has_roles');

        // Flush Spatie's permission cache so the fresh catalog is picked up.
        app('cache')
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
