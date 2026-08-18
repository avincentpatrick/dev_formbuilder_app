<?php

declare(strict_types=1);

use App\Enums\AttachmentKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one unified polymorphic file table (data-dictionary §10, Increment G6) — replacing legacy's
 * `image_path`/`file_path`/`excel_path`/`pdf_path` column sprawl with a single model every channel
 * that produces a file shares: respondent submission media, OCR source scans, avatars, branding logos,
 * feedback screenshots, export artifacts, archived webhook payloads (the nine {@see AttachmentKind}).
 *
 * Ownership is polymorphic via `uuidMorphs('attachable')` — `attachable_type` (a short morph-map alias:
 * `submission` / `form_field` / …, registered in AppServiceProvider) + `attachable_id`, with NO database
 * FK (the accepted trade-off of polymorphic associations, since the target table varies by type). The
 * repo's first `morphTo`. Respondent uploads stage against the `form_field` they answer and are re-pointed
 * to the `submission` at persist (SubmissionPipeline).
 *
 * Carries `tenant_id` → strict RLS (`withTenantIsolation`); the migration linter requires the isolation
 * call for any tenant_id-bearing table. Storage `disk` defaults to `'local'` (the Phase-1 backing store,
 * deployment-infrastructure.md §7) rather than the data-dictionary's aspirational `'s3'`; the write path
 * sets `disk` from `config('filesystems.default')` so an S3 swap is config-only. The object key
 * (`path`) is server-generated `tenants/{tenant_id}/{kind}/...` — `original_filename` is display metadata
 * only, never used to build the key (threat-model §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Polymorphic owner (no DB FK — target table varies by type). Short aliases via morph map.
            $table->uuidMorphs('attachable');

            $table->string('kind', 30);                    // AttachmentKind enum
            $table->string('disk', 30)->default('local');  // Laravel filesystem disk (Phase-1 default: local)
            $table->string('path', 500);                   // server-generated object key: tenants/{tenant_id}/{kind}/...
            $table->string('original_filename', 255)->nullable(); // display metadata only (PII — may embed a name)
            $table->string('mime_type', 150)->nullable();  // content-sniffed at upload, never the client header
            $table->bigInteger('size_bytes')->nullable();  // also feeds the storage_bytes usage metric
            $table->string('checksum_sha256', 64)->nullable();

            $table->integer('width')->nullable();           // images only
            $table->integer('height')->nullable();          // images only
            $table->integer('duration_seconds')->nullable(); // audio/video only (deferred extraction — null for now)

            $table->boolean('is_encrypted_at_rest')->default(false); // set when the owning field is is_sensitive
            $table->boolean('is_pii')->default(false);               // denormalized owning field/submission PII flag
            $table->string('virus_scan_status', 20)->default('pending'); // ScanStatus — not served until servable
            $table->decimal('ocr_confidence_avg', 5, 2)->nullable();     // only for kind = ocr_source_scan

            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete(); // null for guests

            $table->timestampsTz();
            $table->softDeletesTz(); // trash grace period precedes the actual object-deletion job

            $table->index(['tenant_id', 'kind']);            // per-tenant/kind storage accounting
            $table->index(['tenant_id', 'virus_scan_status']); // scan-job + serving-gate scans
        });

        withTenantIsolation('attachments');
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
