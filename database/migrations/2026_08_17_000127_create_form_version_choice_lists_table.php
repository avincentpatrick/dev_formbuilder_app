<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantScopedTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The choice lists a form version takes from uploaded CSV files (M141, `R-f69aab42`, `D92` = A, `D95`) — Kobo's
 * `select_one_from_file`: one file per cascade level, with `name` (a unique code) and `label` columns, and a column
 * named after the level above.
 *
 * ── THE ROWS ARE STORED PARSED, AND THE FILE IS NOT KEPT ─────────────────────────────────────────────────────
 * A list is read once, at upload, into `columns` (the header, lower-cased) and `rows` (each a list of strings in
 * that column order). Nothing reads the bytes again, so there is no attachment, no virus scan of a file nobody
 * opens, and no orphan to collect. A PSGC barangay list is about 42,000 rows; it sits in one `jsonb` value here.
 *
 * ── FROZEN PER VERSION, AND TURNED INTO OPTIONS AT PUBLISH ───────────────────────────────────────────────────
 * The DRAFT's cascade fields name their lists (`config.levels[].list`) and hold no options, so the builder never
 * carries the list. `PublishService` builds the options from these rows into the version it publishes
 * (`ChoiceListMaterializer`), so every server-side reader of a published field — validation, export labels, OCR —
 * sees an ordinary cascade. `SchemaTreeCloner` copies the rows forward and strips the options again.
 *
 * ── THE DRAFT-CHILD RLS SHAPE, AS `form_version_reference_files` ────────────────────────────────────────────
 * Anyone in the tenant may read; a write is accepted only while the version is a draft. Writers take the `forms`
 * row lock first, because the policy reads the committed status. Composite foreign key to the version, CASCADE, so
 * a discarded draft takes its lists with it (ADR-0002 §D5). Nothing withheld; the table joins
 * {@see TenantScopedTables::STRICT}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_version_choice_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('form_version_id');
            // The name a cascade level refers to: the file's name without `.csv`, as Kobo names a list.
            $table->string('name', 64);
            // The file the author uploaded, for the panel; never read again.
            $table->string('file_name', 255);
            // The header, lower-cased and trimmed, in file order.
            $table->jsonb('columns');
            // Every data row, each a list of strings in `columns` order.
            $table->jsonb('rows');
            $table->unsignedInteger('row_count');
            $table->timestampsTz();

            // One list per name per version. Leads with the tenant (ADR-0002 §D1).
            $table->unique(['tenant_id', 'form_version_id', 'name'], 'form_version_choice_lists_unique');
        });

        DB::statement(
            'ALTER TABLE form_version_choice_lists ADD CONSTRAINT form_version_choice_lists_version_fk '
            .'FOREIGN KEY (tenant_id, form_version_id) REFERENCES form_versions (tenant_id, id) ON DELETE CASCADE'
        );

        withTenantIsolation('form_version_choice_lists', 'draft_child');
    }

    public function down(): void
    {
        Schema::dropIfExists('form_version_choice_lists');
    }
};
