<?php

declare(strict_types=1);

use App\Services\Forms\SchemaBlueprintMaterializer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable single-question definitions (data-dictionary §11) — the "question library" the builder inserts a
 * field FROM and saves a field TO. Increment G9b, the second NULLABLE-GLOBAL adopter after `form_templates`
 * (ADR-0002 §D2 named exception): a NULL `tenant_id` is a platform/system question readable by every tenant
 * (the seeded starter library), a non-null one is a tenant's own saved question. Unlike a whole-form template,
 * a library item stores a single field DECOMPOSED into its own columns (`field_type` + `default_*`) rather
 * than a `schema_blueprint`; the model reconstructs the SchemaSnapshotSerializer field shape from them so
 * {@see SchemaBlueprintMaterializer::materializeField} can insert it into a draft.
 *
 * The nullable FK is declared with a manual ->foreign (not ->foreignUuid()->constrained(), which isn't
 * nullable-friendly) so the migration linter's declares_tenant_column rule still sees the literal `tenant_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_library', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(); // NULL ⇒ platform/system question, readable by every tenant

            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('category', 60)->nullable();

            $table->string('field_type', 40); // App\Enums\FieldType backing value (same catalog as form_fields)
            $table->string('default_label', 500);
            $table->jsonb('default_label_translations')->nullable();
            $table->text('default_hint')->nullable();
            $table->jsonb('default_config')->default('{}');       // copied onto the new form_fields.config
            $table->jsonb('default_validations')->default('[]');  // copied into new form_field_validations

            $table->integer('usage_count')->default(0);
            $table->boolean('is_active')->default(true); // soft on/off — preferred over delete, preserves usage_count
            $table->uuid('created_by')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            // tenant_id leads the picker filter (ADR-0002 §D1); usage_count drives the "popular" sort.
            $table->index(['tenant_id', 'is_active']);
            $table->index('usage_count');
        });

        withTenantIsolation('field_library', 'nullable_global');
    }

    public function down(): void
    {
        Schema::dropIfExists('field_library');
    }
};
