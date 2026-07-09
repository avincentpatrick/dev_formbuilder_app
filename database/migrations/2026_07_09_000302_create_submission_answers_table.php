<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The variable answer payload (data-dictionary §8) — one JSONB document per submission, the hybrid
 * model's flexible half. A strict 1:1 with `submissions`: `submission_id` is BOTH primary key and
 * FK (ON DELETE CASCADE), so no surrogate id exists and deleting a submission takes its answers with
 * it. Repeat-group instances live as native JSONB arrays keyed by the section `key` inside `answers`
 * (no separate repeat table). `form_version_id` is denormalized so field defs resolve without a join.
 *
 * RLS: `strict` — `tenant_id` is carried here (denormalized) precisely so the row is directly tenant-
 * scoped rather than reachable only through `submissions`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_answers', function (Blueprint $table): void {
            $table->foreignUuid('submission_id')->primary()->constrained('submissions')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('form_version_id')->constrained('form_versions')->cascadeOnDelete();

            $table->jsonb('answers')->default('{}');            // keyed by form_fields.key / form_sections.key
            $table->string('answers_schema_checksum', 64)->nullable(); // copy of form_versions.checksum
            $table->jsonb('attachment_refs')->default('[]');
            $table->smallInteger('completeness_percent')->nullable(); // Phase-3 save/resume progress
            $table->timestampTz('last_saved_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'form_version_id']);
        });

        withTenantIsolation('submission_answers');
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_answers');
    }
};
