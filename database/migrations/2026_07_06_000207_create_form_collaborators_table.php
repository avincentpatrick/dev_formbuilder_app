<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-form access scoping (multi-tenancy-rbac-design.md §8), deferred from Increment B2c to here since
 * it FKs `forms`. One row grants a Form Editor/Reviewer the per-form `.own` capability for a specific
 * form; Owner/Admin get tenant-wide `.any` without a row. Strictly tenant-scoped → strict RLS. The
 * FormPolicy composition that reads this table lands in D2; D1 lands the table + isolation only.
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
            $table->string('capacity', 10); // FormCollaboratorCapacity enum: editor | reviewer
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
