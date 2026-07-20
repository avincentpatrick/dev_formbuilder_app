<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-form access scoping (multi-tenancy-rbac-design.md §8), deferred from Increment B2c to here since
 * it FKs `forms`. One row grants a Form Editor/Reviewer the per-form `.own` capability for a specific
 * form; Owner/Admin get tenant-wide `.any` without a row. Strictly tenant-scoped → strict RLS.
 *
 * RETIRED IN INCREMENT G10a — generalized into the polymorphic `resource_grants`, which scopes to a form
 * OR a `scope_nodes` hierarchy node. This file is deliberately KEPT rather than deleted: `migrate:fresh`
 * replays every migration in order, and the G10a backfill selects from this table. On a fresh database
 * the table is created here and dropped again five migrations later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_collaborators', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('form_id')->constrained('forms')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('capacity', 10); // editor | reviewer (was the FormCollaboratorCapacity enum)
            $table->foreignUuid('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            // One capacity per (form, user); tenant_id leads (ADR-0002 §D1).
            $table->unique(['tenant_id', 'form_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });

        withTenantIsolation('form_collaborators');
    }

    public function down(): void
    {
        Schema::dropIfExists('form_collaborators');
    }
};
