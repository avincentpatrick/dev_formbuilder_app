<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Groups fields within one form_version, optionally as a repeat group (data-dictionary §4). Content
 * child of form_versions → the `draft_child` RLS shape: writes only land while the owning version is a
 * draft; SELECT stays open so published/superseded content renders. `key` is a stable slug unique per
 * version (required by the canonical serializer's sort + the field→section by-key reference).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_sections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('form_version_id')->constrained('form_versions')->cascadeOnDelete();
            $table->string('key', 150); // stable across versions; unique per version
            $table->string('label', 255);
            $table->jsonb('label_translations')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('description_translations')->nullable();
            $table->integer('sequence')->default(0);
            $table->string('icon', 50)->nullable();
            $table->string('color', 7)->nullable();
            $table->boolean('is_repeatable')->default(false);
            $table->smallInteger('min_instances')->nullable();
            $table->smallInteger('max_instances')->nullable();
            $table->text('relevant_expression')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'form_version_id', 'key']);
            $table->index(['tenant_id', 'form_version_id', 'sequence']);
        });

        // §4/Design Note: min_instances <= max_instances when both set (repeat-group sanity).
        DB::statement(
            'ALTER TABLE form_sections ADD CONSTRAINT form_sections_instances_chk '
            .'CHECK (min_instances IS NULL OR max_instances IS NULL OR min_instances <= max_instances)'
        );

        withTenantIsolation('form_sections', 'draft_child');
    }

    public function down(): void
    {
        Schema::dropIfExists('form_sections');
    }
};
