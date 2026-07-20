<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Enums\ResourceScopeable;
use App\Support\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The generalized per-instance access grant (Increment G10a) — the polymorphic successor to
 * `form_collaborators` (multi-tenancy-rbac-design.md §8, whose closing paragraph nominates that table's
 * two-layer composition as "the general pattern for any future resource that needs per-instance scoping
 * beyond the tenant level").
 *
 * `scopeable` is a real Eloquent `morphTo` resolved through the framework's own morph map — never a
 * hand-rolled switch on a level column, which is the anti-pattern technical-architecture.md §5 row 11
 * and PRD.md:423 explicitly forbid. A grant on a `form` is direct access; a grant on a `scope_node`
 * covers every form assigned to that node, plus its descendants when `includes_descendants` is set.
 *
 * Strictly tenant-scoped, but NOT the plain strict shape — see the isolation call at the bottom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Explicit columns rather than uuidMorphs(): uuidMorphs() also emits a (type, id) index whose
            // LEADING column is not tenant_id — an ADR-0002 §D1 deviation the `attachments` table carries
            // and which we decline to repeat. The three indexes below all lead with tenant_id.
            $table->string('scopeable_type', 30); // morph-map alias: 'form' | 'scope_node'
            $table->uuid('scopeable_id');         // NO DB FK — the target table varies by type

            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('capacity', 20);                          // ResourceCapacity
            $table->boolean('includes_descendants')->default(false); // meaningful only for scope_node targets
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();

            // Hard deletes, matching form_collaborators: revoke means gone, not resurrectable by a bug.
            $table->timestampsTz();

            // EXPLICIT SHORT NAMES: the auto-generated names for these column lists exceed PostgreSQL's
            // 63-byte NAMEDATALEN and would be SILENTLY TRUNCATED — risking a collision between the
            // unique and the target index, which share a long common prefix.
            //
            // `capacity` is deliberately NOT in the unique key (RBAC §8: one capacity per target), so
            // demoting editor → reviewer REPLACES the row rather than adding a second one that would
            // silently leave edit rights standing.
            $table->unique(
                ['tenant_id', 'scopeable_type', 'scopeable_id', 'user_id'],
                'resource_grants_target_user_unique'
            );
            $table->index(['tenant_id', 'user_id', 'capacity'], 'resource_grants_resolver_idx');
            $table->index(['tenant_id', 'scopeable_type', 'scopeable_id'], 'resource_grants_target_idx');
        });

        // Pin the alias set at the database so the RLS guard's per-alias branch set is provably
        // exhaustive — an unregistered type cannot be stored and then fall through every branch.
        $aliases = implode(', ', array_map(
            static fn (string $alias): string => "'".$alias."'",
            ResourceScopeable::aliases()
        ));
        DB::statement(
            'ALTER TABLE resource_grants ADD CONSTRAINT resource_grants_scopeable_type_check '
            ."CHECK (scopeable_type IN ({$aliases}))"
        );

        $capacities = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            ResourceCapacity::values()
        ));
        DB::statement(
            'ALTER TABLE resource_grants ADD CONSTRAINT resource_grants_capacity_check '
            ."CHECK (capacity IN ({$capacities}))"
        );

        // "Include descendants" is meaningless on a form target; forbidding it keeps every grant row's
        // blast radius legible from the row itself.
        DB::statement(
            'ALTER TABLE resource_grants ADD CONSTRAINT resource_grants_descendants_check '
            ."CHECK (scopeable_type = 'scope_node' OR includes_descendants = false)"
        );

        // NOT withTenantIsolation() — this table needs the polymorphic-target guard, because a morph has
        // no database FK and the plain strict shape's `WITH CHECK (tenant_id = ctx)` would ACCEPT a row
        // pointing at another tenant's form. Here that row is an authorization decision input, not a
        // filing pointer, so the `attachments` precedent does not transfer.
        //
        // This must be the SOLE write policy per command: Postgres OR-combines same-command permissive
        // policies, so ALSO calling withTenantIsolation('resource_grants') would silently widen INSERT
        // back to bare tenant equality and still pass CI.
        TenantIsolation::resourceGrantGuard('resource_grants', ResourceScopeable::targetTables());
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_grants');
    }
};
