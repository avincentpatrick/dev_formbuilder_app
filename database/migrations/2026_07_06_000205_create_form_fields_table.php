<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The center of the schema (data-dictionary §5) — every form-content rule hangs off a field. Content
 * child of form_versions → the `draft_child` RLS shape. `form_section_id` is nullable (NULL = ungrouped
 * top-level) and ON DELETE SET NULL, so deleting a section orphans its fields rather than cascading.
 * `key` is unique per version (referenced as ${key} in expressions and for cross-version analytics).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_fields', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('form_version_id')->constrained('form_versions')->cascadeOnDelete();
            $table->foreignUuid('form_section_id')->nullable()->constrained('form_sections')->nullOnDelete();
            $table->string('key', 150);
            $table->string('field_type', 40); // FieldType enum
            $table->jsonb('config')->default('{}'); // per-type settings
            $table->string('label', 500);
            $table->jsonb('label_translations')->nullable();
            $table->text('hint')->nullable();
            $table->jsonb('hint_translations')->nullable();
            $table->string('placeholder', 255)->nullable();
            $table->text('default_value')->nullable();
            $table->boolean('default_value_is_expression')->default(false);
            $table->string('is_required', 20)->default('optional'); // RequiredMode enum
            $table->text('relevant_expression')->nullable();
            $table->string('appearance', 60)->nullable();
            $table->integer('sequence')->default(0);
            $table->integer('section_sequence')->nullable();
            $table->boolean('is_pii')->default(false);
            $table->boolean('is_sensitive')->default(false);
            $table->boolean('is_queryable')->default(false);
            $table->string('indexed_data_type', 20)->nullable(); // IndexedDataType enum; required when queryable
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'form_version_id', 'key']);
            $table->index(['tenant_id', 'form_version_id', 'sequence']);
            $table->index(['tenant_id', 'form_section_id']);
        });

        withTenantIsolation('form_fields', 'draft_child');
    }

    public function down(): void
    {
        Schema::dropIfExists('form_fields');
    }
};
