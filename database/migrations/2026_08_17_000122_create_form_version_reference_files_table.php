<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantScopedTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The reference files a form version shows its respondents (M132, `R-bf49e4c1`) — a PDF or an image an author
 * attaches for respondents to read before they answer.
 *
 * ── FROZEN PER PUBLISHED VERSION, AND THE BYTES ARE NOT COPIED (`D61` = B) ──────────────────────────────────
 * A row here is `D61`'s "reference row": it says that THIS version shows THAT file under THIS label. The file itself
 * is one `attachments` row of kind `form_reference_file`, owned by the form, and stored once per distinct SHA-256 per
 * form — so publishing copies these rows (through `SchemaTreeCloner`, beside the version's fields) and never a byte,
 * and the storage gauge, which sums attachment rows, counts each file once however many versions show it.
 *
 * ── THE DRAFT-CHILD RLS SHAPE IS WHAT FREEZES IT ─────────────────────────────────────────────────────────────
 * `withTenantIsolation(..., 'draft_child')` — the shape `form_sections`, `form_fields` and
 * `form_field_validations` carry: anyone in the tenant may read, and an INSERT, UPDATE or DELETE is accepted only
 * while the row's version is a draft. A published version's files can therefore not be changed by any path, which
 * is the whole of `D61`. ⚠️ The policy reads the COMMITTED status, so every writer takes the `forms` row lock first
 * — `PublishService` relies on exactly that (its step-0 note), and `FormReferenceFileService` does it.
 *
 * ── TWO COMPOSITE FOREIGN KEYS, AND THEY DELETE DIFFERENTLY ────────────────────────────────────────────────
 * Composite for ADR-0002 §D5's reason (referential actions run beneath RLS). To the version: CASCADE, because
 * archiving a form deletes its draft version, and a discarded draft takes its list with it. To the attachment:
 * NO ACTION, so a hard delete of a file a version still shows fails loudly instead of emptying a frozen version.
 * The service only ever soft-deletes, and only a file no version references.
 *
 * Strict tenant read, nothing withheld: a label the author typed and two ids. The table joins
 * {@see TenantScopedTables::STRICT}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_version_reference_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('form_version_id');
            $table->uuid('attachment_id');
            // What a respondent sees in place of the file name; the original name until the author renames it.
            $table->string('label', 120);
            // Display order within the version: the order the author attached them in.
            $table->unsignedSmallInteger('position');
            $table->timestampsTz();

            // One file once per version. Leads with the tenant (ADR-0002 §D1), so no boundary exception is owed.
            $table->unique(['tenant_id', 'form_version_id', 'attachment_id'], 'form_version_reference_files_unique');
            $table->index(['tenant_id', 'attachment_id']);
        });

        DB::statement(
            'ALTER TABLE form_version_reference_files ADD CONSTRAINT form_version_reference_files_version_fk '
            .'FOREIGN KEY (tenant_id, form_version_id) REFERENCES form_versions (tenant_id, id) ON DELETE CASCADE'
        );
        DB::statement(
            'ALTER TABLE form_version_reference_files ADD CONSTRAINT form_version_reference_files_attachment_fk '
            .'FOREIGN KEY (tenant_id, attachment_id) REFERENCES attachments (tenant_id, id)'
        );

        withTenantIsolation('form_version_reference_files', 'draft_child');
    }

    public function down(): void
    {
        Schema::dropIfExists('form_version_reference_files');
    }
};
