<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantScopedTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A folder the forms list files forms into (M131, `R-9e634897`, `D78`): flat, shared by the whole workspace,
 * one per form. The form side is `forms.folder_id`, added by the next migration.
 *
 * ── A FILING AXIS, NOT AN AUTHORIZATION ONE, AND NOT AN ANALYTICS ONE ───────────────────────────────
 * `forms.scope_node_id` already groups forms, and it must not be reused for this: a scope node is what a
 * grant is made against, so filing a form there would change who may read it. A folder changes nothing
 * about access — the list filters and counts by it only among the rows `Form::scopeVisibleTo()` already
 * admitted. ADR-0011 §D6 made `scope_node_id` the analytics grouping axis and coined no tag model; a
 * single-valued folder does not meet that ADR's trigger for one (a form in two programs), and the ADR
 * carries a dated note saying so.
 *
 * ── THE NAME IS UNIQUE PER WORKSPACE, IGNORING CASE ─────────────────────────────────────────────────
 * An expression index on `lower(name)`, so "Clinics" and "clinics" cannot both exist: two folders a person
 * cannot tell apart in a list are one folder filed twice. The service turns the violation (SQLSTATE 23505)
 * into a field error, because a check-then-write is a race between two tabs. There is no position column —
 * the list sorts by `lower(name)`, which is the order a person scans a list of names in.
 *
 * ── STRICT RLS, AND NOTHING WITHHELD FROM THE TENANT EXTRACT ────────────────────────────────────────
 * A folder is a name the workspace typed and who typed it. The table joins {@see TenantScopedTables::STRICT}.
 * `created_by` nulls on delete: a departed member must not take a workspace's folders with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_folders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 80);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            // The target `forms_folder_fk` points at (ADR-0002 §D5): a composite key, so a referential action
            // running beneath RLS can only ever touch a row of the folder's own workspace.
            $table->unique(['tenant_id', 'id'], 'form_folders_tenant_id_id_unique');
        });

        DB::statement('CREATE UNIQUE INDEX form_folders_tenant_name_unique ON form_folders (tenant_id, lower(name))');

        withTenantIsolation('form_folders'); // strict
    }

    public function down(): void
    {
        Schema::dropIfExists('form_folders');
    }
};
