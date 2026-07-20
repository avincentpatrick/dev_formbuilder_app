<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant-defined scoping hierarchy (Increment G10a; PRD §6/§7). This is the generic successor to the
 * legacy system's PSGC/catchment-area model, carrying ZERO platform semantics: `node_type` and `code` are
 * the tenant's own opaque labels which the platform stores and displays but NEVER switches on. A tenant
 * that needs Philippine geography models it here as their own reference data (PRD.md:423).
 *
 * Tree representation is an adjacency list (`parent_id` is the source of truth) PLUS a materialized
 * self-inclusive `path` of '/'-delimited ancestor uuids, written only by `ScopeNodeService`. Authorization
 * resolves DOWNWARD from a grant (`path LIKE :granted_path || '%'`), never upward with a leading-wildcard
 * substring — a leading `%` is unindexable by any btree, and this predicate sits inside the submissions
 * inbox query. The constant prefix compiles to range quals against the `(tenant_id, path)` index ONLY
 * under `COLLATE "C"`, which is why the collation below is load-bearing rather than cosmetic.
 *
 * Strictly tenant-scoped → strict RLS. The composite self-FK is the first literal implementation of
 * ADR-0002 §D5 in this repo: PostgreSQL runs referential actions BYPASSING RLS (see
 * TenantIsolation::formVersionGuardSql's docblock), so a single-column `parent_id` FK would let one
 * tenant's delete cascade into another tenant's rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scope_nodes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Composite self-FK below, so NOT foreignUuid()->constrained() (which would emit a
            // single-column FK that referential actions could follow across tenants).
            $table->uuid('parent_id')->nullable();

            $table->string('name', 150);
            $table->string('code', 60)->nullable();      // the TENANT's own opaque code; never interpreted
            $table->string('node_type', 60)->nullable(); // tenant display label; NEVER switched on in PHP

            // '/{rootId}/…/{ownId}/' — self-inclusive. COLLATE "C" is REQUIRED for the prefix index below
            // to serve `LIKE 'const%'`; without it every node lookup silently degrades to a Seq Scan
            // (correct results, no test failure — guarded by the EXPLAIN assertion in ScopeNodeScaleTest).
            $table->string('path', 512)->collation('C');

            $table->smallInteger('depth')->default(0);
            $table->integer('position')->default(0);

            // Deactivation instead of a soft delete: a `deleted_at` the resolver must remember to filter
            // is an authorization landmine. ResourceGrantResolver filters `is_active` in BOTH its query
            // shapes (the in-memory check and the inbox list twin), with tests on each.
            $table->boolean('is_active')->default(true);

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz(); // deliberately NO softDeletesTz() — see above

            // Composite-FK target. Postgres requires a UNIQUE on the referenced column list.
            $table->unique(['tenant_id', 'id'], 'scope_nodes_tenant_id_id_unique');

            // MATCH SIMPLE (the default): a NULL parent_id skips the constraint entirely, so roots are
            // free. cascadeOnDelete (not restrictOnDelete) so the `tenants` teardown cascade cannot
            // deadlock against this self-referencing edge.
            $table->foreign(['tenant_id', 'parent_id'], 'scope_nodes_parent_fk')
                ->references(['tenant_id', 'id'])->on('scope_nodes')->cascadeOnDelete();

            $table->unique(['tenant_id', 'code']);       // tenant_id leads (ADR-0002 §D1)
            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'path']);        // serves LIKE 'const%' ONLY because of COLLATE "C"
            $table->index(['tenant_id', 'is_active']);
        });

        // Depth cap bounds `path` at 13 × 37 = 481 bytes, inside the varchar(512) above.
        DB::statement('ALTER TABLE scope_nodes ADD CONSTRAINT scope_nodes_depth_max CHECK (depth BETWEEN 0 AND 12)');
        // A one-node cycle is the only cycle expressible at INSERT time (a new node has no descendants);
        // longer cycles are impossible because re-parenting is rejected outright in G10a (ScopeNode::booted).
        DB::statement('ALTER TABLE scope_nodes ADD CONSTRAINT scope_nodes_no_self_parent CHECK (parent_id IS NULL OR parent_id <> id)');

        withTenantIsolation('scope_nodes');
    }

    public function down(): void
    {
        Schema::dropIfExists('scope_nodes');
    }
};
