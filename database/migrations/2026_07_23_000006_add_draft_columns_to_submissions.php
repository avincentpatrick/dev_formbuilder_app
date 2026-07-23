<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Increment H9a — the per-SUBMISSION draft-resume columns (the server-side draft substrate shared by
 * save-and-resume and OCR). `submission_answers` already carries per-ANSWER `completeness_percent` /
 * `last_saved_at`, but resume is per-SUBMISSION (a resume token is scoped to one `submissions.id`), so the
 * authoritative draft state lives here:
 *
 *   - `completeness_percent` — a cached, relevance-unaware progress indicator (answered answerable fields /
 *     answerable fields), stamped on every draft save so the resume UX can show "Saved · N%".
 *   - `last_saved_at` — the most recent draft-save time (distinct from `submitted_at`, set only on promotion).
 *   - `draft_expires_at` — when an un-promoted draft is reapable (stamped `now()+30d` at draft creation;
 *     the tenant-configurable override + the expiry reaper are H9b/H10). Null once the row is promoted.
 *
 * `'draft'` is already a legal `SubmissionStatus` value and the `status` column default, so no status change
 * is needed. No `withTenantIsolation()` re-emit: `submissions` already carries strict RLS from
 * 2026_07_09_000301_create_submissions_table, and RLS policies are ROW predicates — adding a column does not
 * weaken them (the same reasoning as 2026_07_16_000001_add_answers_content_checksum_to_submission_answers and
 * 2026_07_20_000002_add_scope_node_id_to_forms; the migration linter skips alter-only migrations anyway).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->smallInteger('completeness_percent')->nullable()->after('locale');
            $table->timestampTz('last_saved_at')->nullable()->after('completeness_percent');
            $table->timestampTz('draft_expires_at')->nullable()->after('last_saved_at');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->dropColumn(['completeness_percent', 'last_saved_at', 'draft_expires_at']);
        });
    }
};
