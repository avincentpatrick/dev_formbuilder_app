<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The selective typed projection (data-dictionary §9) — one row per `is_queryable` field per
 * submission, written transactionally alongside the JSONB document in the pipeline's persist stage.
 * The point of five typed nullable value columns (rather than one stringly-typed column) is that each
 * gets a real narrow B-tree index for fast filtering/sorting/aggregation — the hot-path half of the
 * hybrid model. Only scalar, non-repeated values are projected; cross-repeat aggregates stay in
 * `submission_answers.answers` and are computed by the expression engine at evaluation time.
 *
 * PK is `bigint identity`, not uuid: a pure internal projection row, never addressed externally, so a
 * narrower key measurably helps index size at volume. Still carries `tenant_id` → RLS `strict` (and the
 * migration linter requires the isolation call for any tenant_id-bearing table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_answer_index', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('submission_id')->constrained('submissions')->cascadeOnDelete();
            $table->foreignUuid('form_version_id')->constrained('form_versions')->cascadeOnDelete();
            $table->foreignUuid('form_field_id')->constrained('form_fields')->cascadeOnDelete();
            $table->string('field_key', 150); // denormalized form_fields.key — stable across versions

            // Exactly one is populated per row, per the field's IndexedDataType.
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 30, 10)->nullable(); // widened to unbounded numeric below
            $table->boolean('value_boolean')->nullable();
            $table->date('value_date')->nullable();
            $table->timestampTz('value_datetime')->nullable();

            $table->timestampsTz();

            $table->unique(['submission_id', 'form_field_id']); // one projection per queryable field
            $table->index(['tenant_id', 'field_key', 'value_text']);
            $table->index(['tenant_id', 'field_key', 'value_number']);
            $table->index(['tenant_id', 'field_key', 'value_date']);
            $table->index(['tenant_id', 'field_key', 'value_datetime']);
        });

        // value_number as unbounded numeric (data-dictionary §9) — the Blueprint decimal above only
        // exists to declare the column; widen it so no precision/scale cap can silently truncate.
        DB::statement('ALTER TABLE submission_answer_index ALTER COLUMN value_number TYPE numeric');

        withTenantIsolation('submission_answer_index');
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_answer_index');
    }
};
