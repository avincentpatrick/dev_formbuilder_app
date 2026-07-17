<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whole-form blueprints (data-dictionary §12), instantiated by CLONING into a brand-new form rather than
 * by a live-mutable reference. Increment G9a — the first real adopter of the NULLABLE-GLOBAL tenant shape
 * (ADR-0002 §D2 named exception), succeeding the throwaway `global_probes` harness: a NULL `tenant_id` is
 * a platform/system template (the onboarding gallery, Doc #25) readable by every tenant; a non-null one is
 * a tenant's own saved template. `schema_blueprint` reuses the exact `form_versions.schema_snapshot` shape
 * (App\Services\Forms\SchemaSnapshotSerializer) so one denormalization format serves both "restore version"
 * and "instantiate template". The nullable FK is declared the same way `global_probes` does (a manual
 * ->foreign, not ->foreignUuid()->constrained(), which isn't nullable-friendly) so the migration linter's
 * declares_tenant_column rule still sees the literal `tenant_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(); // NULL ⇒ platform/system template, readable by every tenant

            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('category', 60)->nullable();
            $table->uuid('cover_image_attachment_id')->nullable();
            $table->jsonb('schema_blueprint'); // required; the version-shaped snapshot (§12)
            $table->uuid('source_form_version_id')->nullable(); // traceability for "save as template"
            $table->boolean('is_public')->default(false);
            $table->integer('usage_count')->default(0);
            $table->uuid('created_by')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('cover_image_attachment_id')->references('id')->on('attachments')->nullOnDelete();
            $table->foreign('source_form_version_id')->references('id')->on('form_versions')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            // tenant_id leads the gallery filter (ADR-0002 §D1); usage_count drives the "popular" sort.
            $table->index(['tenant_id', 'is_public']);
            $table->index('usage_count');
        });

        withTenantIsolation('form_templates', 'nullable_global');
    }

    public function down(): void
    {
        Schema::dropIfExists('form_templates');
    }
};
