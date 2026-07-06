<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The immutable snapshot per publish (data-dictionary §3) — submissions will FK here, never to the
 * live, mutable forms/form_fields rows (the plan's core structural fix, §2.3). No soft-deletes:
 * versions are append-only history; a draft is discarded by hard delete (which its RLS shape permits
 * only while status = 'draft').
 *
 * RLS: the `form_version` shape (form-versioning-schema-migration.md §2) — strict SELECT/INSERT/UPDATE
 * (UPDATE unconstrained by status so publish can move draft→published→superseded) plus a DELETE that
 * matches only a draft, so a published/superseded version is undeletable. That undeletability is what
 * keeps this table's ON DELETE CASCADE from ever reaching (and wiping) the immutable child rows.
 *
 * Must exist, with its RLS in place, before form_sections/form_fields/form_field_validations, whose
 * draft-child guard runs an EXISTS against this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('form_id')->constrained('forms')->cascadeOnDelete();
            $table->integer('version_number'); // sequential per form, starting at 1
            $table->string('status', 20)->default('draft'); // FormVersionStatus enum
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->jsonb('schema_snapshot'); // canonical, id-free flatten; '{}' until publish
            $table->text('change_summary')->nullable();
            $table->string('checksum', 64)->nullable(); // SHA-256 of the canonical schema_snapshot
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampsTz();

            // (form_id, version_number) unique — tenant_id leads (ADR-0002 §D1); form_id functionally
            // determines tenant_id, so this is equivalent to the doc's stated unique(form_id, version).
            $table->unique(['tenant_id', 'form_id', 'version_number']);
            $table->index(['tenant_id', 'form_id', 'status']);
        });

        withTenantIsolation('form_versions', 'form_version');
    }

    public function down(): void
    {
        Schema::dropIfExists('form_versions');
    }
};
