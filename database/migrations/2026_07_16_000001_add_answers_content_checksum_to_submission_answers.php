<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Increment G8c — a SHA-256 over the canonical serialization of a submission's ANSWER content (distinct
 * from the sibling `answers_schema_checksum`, which hashes the form *schema*). It lets the Submission
 * Pipeline tell a byte-identical idempotent replay of a `client_submission_uuid` (→ 200 no-op) apart from
 * the same uuid carrying materially DIFFERENT answers (→ 409 `submission_conflict`, a genuine concurrent
 * edit surfaced for manual review — docs/offline-first-sync-design.md §5).
 *
 * Nullable + no backfill: rows created before this migration keep `null`, and the pipeline treats a null
 * stored checksum as "cannot compare" → the existing idempotent no-op, so legacy replays never false-conflict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submission_answers', function (Blueprint $table): void {
            $table->string('answers_content_checksum', 64)->nullable()->after('answers_schema_checksum');
        });
    }

    public function down(): void
    {
        Schema::table('submission_answers', function (Blueprint $table): void {
            $table->dropColumn('answers_content_checksum');
        });
    }
};
