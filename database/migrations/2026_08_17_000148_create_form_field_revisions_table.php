<?php

declare(strict_types=1);

use App\Enums\FieldRevisionKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `form_field_revisions` — a question's history: who changed which setting, when, from what to what (M188, `R-3894369d`;
 * data-dictionary §42).
 *
 * One row per save that changed something, never edited: append-only RLS (SELECT + INSERT), no `updated_at`, and
 * `created_at` set by Eloquent. Keyed by `lineage_id` (`000147`) within `form_id`, so the history survives publish and
 * renames; `field_key` and `field_type` record what the question was called and what it was at the time.
 *
 * ⛔ NO FOREIGN KEY TO `forms` OR `form_versions`, DELIBERATELY. The builder writes a revision inside `writeField()` and
 * `convertField()`, after the question row's lock and without the `forms` lock (§3.4); a foreign key would take FOR KEY
 * SHARE on the parent there. Publish holds `forms` FOR UPDATE and then locks every question, and archive deletes the
 * draft version — so the insert would wait on a row a transaction holding this question's row is waiting on, the
 * opposite order (the M91/M92 deadlock class `BuilderLockOrderTest` pins). Both columns are plain references; a
 * revision outlives an archived draft, which is what a history is for. `users` holds no tenant, so `user_id` keeps the
 * plain nullable shape `form_fields.updated_by` has.
 *
 * Not the audit ledger (`docs/audit-compliance-logging-spec.md`: draft edits are not audited; the publish is).
 *
 * ⛔ ADDITIVE (`D137`): a new table; nothing existing is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_field_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('form_id');              // deliberately no FK — see above
            $table->uuid('form_version_id');      // the draft it was made in; deliberately no FK
            $table->uuid('lineage_id');           // form_fields.lineage_id
            $table->string('field_key');          // the key at the time
            $table->string('field_type', 40);     // FieldType, at the time
            $table->string('kind', 20);           // FieldRevisionKind
            $table->jsonb('changes');             // [{setting, before, after}], in the builder's shape
            $table->uuid('put_back_of')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');    // set by Eloquent

            // tenant_id leads (ADR-0002 §D1); a history is always read for one question, newest first.
            $table->index(['tenant_id', 'form_id', 'lineage_id', 'created_at']);
        });

        $quoted = implode(', ', array_map(static fn (string $value): string => "'".$value."'", FieldRevisionKind::values()));

        DB::statement(
            'ALTER TABLE form_field_revisions ADD CONSTRAINT form_field_revisions_kind_check '
            .'CHECK (kind IN ('.$quoted.'))'
        );

        withTenantIsolation('form_field_revisions', 'append_only');
    }

    public function down(): void
    {
        Schema::dropIfExists('form_field_revisions');
    }
};
