<?php

declare(strict_types=1);

use App\Enums\FeedbackSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a report came from, the reference it is known by, and who confirmed the fix — M179 (`R-db9dec62`,
 * `R-129c44c3`, `D150`, `D151`; data-dictionary §21).
 *
 * - `source` — `button` (the Feedback button: every row before this migration, and every one it writes since)
 *   or `relayed` (a comment that reached the team another way, loaded through the support API). NOT NULL with a
 *   CONSTANT default, so PostgreSQL records the default in the catalogue and rewrites no existing row.
 *   `feedback_reports_source_check` is generated from {@see FeedbackSource::values()}; every live row is
 *   `button` the moment the column exists, so a plain `ADD CONSTRAINT` validates at once.
 * - `source_label` — the source line a relayed report shows ("Testers' PDF, Oct 11 — comment 5").
 * - `reference` — the code the testers know a relayed report by (`R1-7a`, `U4`). The support API creates or
 *   updates by it, so it is unique WITHIN A WORKSPACE (a partial index over the non-null values: a button
 *   report has none, and any number may have none). It leads with `tenant_id` — two workspaces may number
 *   their comments alike, and a global unique would be a cross-tenant constraint (ADR-0002 §D5).
 * - `title` — what a relayed report is called on its card; a button report has none and shows its page.
 * - `confirmed_at` / `confirmed_by` — when, and by whom, a tester pressed **It works** (`D151`). `users` holds
 *   no tenant, so the key is the plain shape `resolved_by` already uses.
 * - `last_comment_at` — the newest comment in the report's thread, for sorting and display.
 *
 * ⛔ ADDITIVE (`D137`): seven nullable or defaulted columns, one CHECK every live row satisfies, one partial
 * index; no row is rewritten or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table): void {
            $table->string('source', 15)->default('button');
            $table->string('source_label', 255)->nullable();
            $table->string('reference', 30)->nullable();
            $table->string('title', 200)->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->foreignUuid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_comment_at')->nullable();
        });

        $sources = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            FeedbackSource::values()
        ));

        DB::statement(
            'ALTER TABLE feedback_reports ADD CONSTRAINT feedback_reports_source_check '
            ."CHECK (source IN ({$sources}))"
        );

        DB::statement(
            'CREATE UNIQUE INDEX feedback_reports_tenant_reference_unique '
            .'ON feedback_reports (tenant_id, reference) WHERE reference IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS feedback_reports_tenant_reference_unique');
        DB::statement('ALTER TABLE feedback_reports DROP CONSTRAINT IF EXISTS feedback_reports_source_check');

        Schema::table('feedback_reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['source', 'source_label', 'reference', 'title', 'confirmed_at', 'last_comment_at']);
        });
    }
};
