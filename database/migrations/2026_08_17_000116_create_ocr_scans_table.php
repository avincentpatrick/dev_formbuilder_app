<?php

declare(strict_types=1);

use App\Enums\OcrScanStatus;
use App\Support\Tenancy\TenantScopedTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One uploaded scan of a printed blank form, and what reading it produced (M128 — single-form OCR
 * groundwork 1, `docs/ocr-pipeline-design.md` §3 and §6).
 *
 * ── A STAGING TABLE, NOT A SECOND PERSISTENCE PATH ──────────────────────────────────────────────────
 * §1 makes the one architectural rule: OCR output is a staged proposal that a person reviews, and only a
 * confirmed proposal reaches `SubmissionPipeline`, as an ordinary submission. So this table holds the
 * proposal and nothing downstream reads it as an answer. The confirmation (groundwork 2) writes the
 * submission; this row remembers what the machine read, at what confidence, so the review screen can show
 * the reviewer where to look.
 *
 * ── THE PAGES LIVE IN `attachments`, KIND `ocr_source_scan` ──────────────────────────────────────────
 * `pages` is an ordered list of `{attachment_id, response_path}`. The files go through the shared
 * attachment write path (content-sniffed type, server-generated key, virus scan, storage quota), with the
 * owner alias `ocr_scan`. `response_path` is where the provider's raw answer for that page is kept,
 * privately, beside the scan. That is what lets the calibration on real samples re-run the matcher
 * without paying the provider again. A page whose `response_path` is null has not been read yet, which is
 * how the reading job resumes one page per run. ⚠️ THERE IS NO CONFIDENCE COLUMN HERE: the per-scan average
 * already has a documented home, `attachments.ocr_confidence_avg` (`docs/data-dictionary.md` §10), which the
 * reading job writes on each page, and a second copy would be the two-copies-of-a-fact defect.
 *
 * ── `form_version_id` IS THE VERSION THE PAPER WAS PRINTED FROM, NOT THE CURRENT ONE ─────────────────
 * Every printed page carries the first eight characters of its version's checksum (§2.5.5), and a scan is
 * matched against THAT version's layout. A superseded version is still a valid answer, because paper
 * already in the field outlives a republish (§2.5.1). It is null until a page has been read.
 *
 * ── STRICT RLS, AND NOTHING WITHHELD FROM THE TENANT EXTRACT ────────────────────────────────────────
 * Every column is the tenant's own data: the extraction is their respondents' answers, which the tenant
 * owns exactly as it owns `submission_answers`. The table joins {@see TenantScopedTables::STRICT}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_scans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('form_id');

            // The version the paper was printed from, read off the page's checksum stamp. Null until read.
            // ⚠️ NO FOREIGN KEY, deliberately. `form_versions` has no `(tenant_id, id)` unique to point a
            // composite key at, and a single-column key into a tenant table is the shape
            // `ConstraintBoundaries` exists to stop growing. Nothing is lost: the reading job resolves the
            // id ONLY among the versions of this scan's own form, and a version is never deleted except with
            // its form — which `ocr_scans_form_fk` below already cascades to this row.
            $table->uuid('form_version_id')->nullable();

            // Who uploaded it. `nullOnDelete`: a departed member must not take a workspace's scans with them.
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 20)->default(OcrScanStatus::Queued->value);
            $table->string('provider', 30);
            $table->jsonb('pages');
            $table->jsonb('extraction')->nullable();

            // Runs that ended without progress: a provider failure worth retrying, or a page still waiting
            // for its virus scan. Counted HERE rather than left to the queue, because every run is wrapped in
            // a transaction (`TenantAwareJob::handle()`), so a run that throws rolls back whatever it wrote,
            // and `failed()` is final and only logs. The job releases itself instead of throwing, and marks
            // the scan failed once this reaches `ocr.max_attempts`, so a scan always reaches a final state.
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 40)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'form_id'], 'ocr_scans_form_fk')
                ->references(['tenant_id', 'id'])->on('forms')->cascadeOnDelete();

            // The review list's access path: this form's scans, newest first.
            $table->index(['tenant_id', 'form_id', 'created_at'], 'ocr_scans_form_created_idx');
        });

        $statuses = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            OcrScanStatus::values()
        ));

        DB::statement(
            'ALTER TABLE ocr_scans ADD CONSTRAINT ocr_scans_status_check '
            ."CHECK (status IN ({$statuses}))"
        );

        withTenantIsolation('ocr_scans'); // strict — written by the reading job after insert
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_scans');
    }
};
