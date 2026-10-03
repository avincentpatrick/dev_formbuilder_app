<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reviewed scan becomes an ordinary submission (M129 — single-form OCR groundwork 2,
 * `docs/ocr-pipeline-design.md` §4 and §5).
 *
 * ── THREE COLUMNS, AND NO NEW STATUS ────────────────────────────────────────────────────────────────
 * `status` says how READING went, and a confirmed scan was read. Saving it is a separate fact with its own
 * time and its own person, so it gets its own columns rather than a fifth status — which would also have
 * meant rewriting `ocr_scans_status_check` for a state the reading job never writes.
 *
 * ── `submission_id` CARRIES NO FOREIGN KEY, DELIBERATELY ────────────────────────────────────────────
 * `submissions` has no `(tenant_id, id)` unique to point a composite key at, and a single-column key into a
 * tenant table is the shape `ConstraintBoundaries` exists to stop growing — the same reasoning
 * `form_version_id` records in the create migration. Nothing is lost: the submission is created with the
 * scan's own id as its `client_submission_uuid`, so the pipeline's idempotency already ties one scan to at
 * most one submission, and the confirmation writes this column only after that submission exists.
 *
 * `confirmed_by` is a plain key into `users`, the `uploaded_by` precedent: a departed member must not take
 * the record of who saved a scan with them, so it nulls rather than cascades.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_scans', function (Blueprint $table): void {
            $table->uuid('submission_id')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->foreignUuid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ocr_scans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['submission_id', 'confirmed_at']);
        });
    }
};
