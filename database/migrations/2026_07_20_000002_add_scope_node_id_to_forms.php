<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Point a form at its node in the tenant's scoping hierarchy (Increment G10a). Nullable — a form with no
 * node is reachable only by a DIRECT grant, which is every form's state after the G10a backfill and is
 * exactly what makes G10a's policy rewiring provably behaviour-identical. The picker that populates this
 * column is G10b.
 *
 * No `withTenantIsolation()` call here: `forms` already carries strict RLS from
 * 2026_07_06_000201_create_forms_table, and RLS policies are ROW predicates — adding a column does not
 * weaken them. (The migration linter skips alter-only migrations anyway, but the reasoning is the point.)
 *
 * The FK is composite — ADR-0002 §D5, the same reasoning as `scope_nodes.parent_id`: PostgreSQL runs
 * referential actions BYPASSING RLS, so a single-column `scope_node_id` FK would let tenant A deleting
 * its own node perform a cross-tenant SET NULL *write* against tenant B's forms row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->uuid('scope_node_id')->nullable()->after('owner_user_id');
            $table->index(['tenant_id', 'scope_node_id']); // tenant_id leads (ADR-0002 §D1)
        });

        // PostgreSQL 15+ column-list SET NULL. Blueprint cannot express it, and a plain nullOnDelete()
        // on a COMPOSITE key would try to NULL `tenant_id` too — a NOT NULL violation on every node
        // delete. Naming only `scope_node_id` un-scopes the form and leaves its tenancy intact.
        // PG17 is guaranteed: docker-compose.yml and all CI jobs run postgis/postgis:17-3.5.
        DB::statement(
            'ALTER TABLE forms ADD CONSTRAINT forms_scope_node_fk '
            .'FOREIGN KEY (tenant_id, scope_node_id) REFERENCES scope_nodes (tenant_id, id) '
            .'ON UPDATE CASCADE ON DELETE SET NULL (scope_node_id)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE forms DROP CONSTRAINT IF EXISTS forms_scope_node_fk');

        Schema::table('forms', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'scope_node_id']);
            $table->dropColumn('scope_node_id');
        });
    }
};
