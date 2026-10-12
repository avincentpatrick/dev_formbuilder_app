<?php

declare(strict_types=1);

use App\Enums\OcrScanSource;
use App\Support\Submissions\SubmissionReference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a scan came from, and the code a guest who sent one is given (M165, `R-7173c02c`, `D106`).
 *
 * `source` is `staff` for every scan that exists today (the default) and `guest` for a filled paper copy uploaded
 * from the public link. The staff list marks a guest scan "From the public link"; nothing else reads it.
 *
 * `reference` is the code the guest is shown after uploading — the format of a response's reference
 * ({@see SubmissionReference}), so a staff member can find the scan when the guest quotes it. Null for a staff
 * scan: the uploader is already on the page. ⚠️ A DISPLAY HANDLE, NEVER A CREDENTIAL, as a response's is: it is
 * only ever searched inside the staff scans page, behind that page's gates.
 *
 * The unique index is PARTIAL on `reference IS NOT NULL` and leads with `tenant_id`, `submissions`' shape for its
 * reference: distinct within a workspace, and every staff scan's null costs nothing.
 *
 * ⛔ ADDITIVE ONLY. New columns with defaults; no existing row is rewritten or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_scans', function (Blueprint $table): void {
            $table->string('source', 10)->default(OcrScanSource::Staff->value);
            $table->string('reference', SubmissionReference::LENGTH)->nullable();
        });

        $sources = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            OcrScanSource::values()
        ));

        DB::statement(
            'ALTER TABLE ocr_scans ADD CONSTRAINT ocr_scans_source_check '
            ."CHECK (source IN ({$sources}))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX ocr_scans_tenant_reference_unique '
            .'ON ocr_scans (tenant_id, reference) WHERE reference IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ocr_scans_tenant_reference_unique');
        DB::statement('ALTER TABLE ocr_scans DROP CONSTRAINT IF EXISTS ocr_scans_source_check');

        Schema::table('ocr_scans', function (Blueprint $table): void {
            $table->dropColumn(['source', 'reference']);
        });
    }
};
