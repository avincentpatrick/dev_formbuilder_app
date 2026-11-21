<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantScopedTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A form version's glossary (M245, `R-d0bbb7bb`): each term and its definition, opened by a note's marked word and by
 * the Glossary button on every page, and printed at the end of the paper form and the response PDF.
 *
 * ── A CHILD TABLE, NOT A COLUMN ON `form_versions` ──────────────────────────────────────────────────────────
 * `form_versions`' UPDATE policy is tenant equality alone, because publish must flip a version's status. A glossary
 * column there would be published content on a row anyone in the tenant may update, frozen by application code alone.
 * Here the draft-child guard freezes it in the database: a write is accepted only while the version is a draft, as
 * `form_version_choice_lists` and `form_version_reference_files` are. Writers take the `forms` row lock first, because
 * the policy reads the committed status.
 *
 * ── FROZEN PER VERSION ─────────────────────────────────────────────────────────────────────────────────────────
 * The draft holds its entries; publish freezes them into the version's `schema_snapshot` as `glossary` (omitted when
 * empty, so no older checksum moves) and `SchemaTreeCloner` copies the rows forward to the next draft. A restore takes
 * the source version's. Composite foreign key to the version, CASCADE, so a discarded draft takes its glossary with it
 * (ADR-0002 §D5). Nothing withheld; the table joins {@see TenantScopedTables::STRICT}.
 *
 * `term_key` is `Glossary::key(term)` — spacing collapsed, lower-cased — the key a marked word finds its entry by, and
 * the one unique per version, so "TWG" and "twg" cannot both be defined.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_version_glossary_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('form_version_id');
            // The term as the author wrote it, spacing collapsed: what the Glossary list and the pop-up show.
            $table->string('term', 100);
            // `Glossary::key(term)`. Lower-casing can lengthen a term (İ is two characters lower-cased), hence 255.
            $table->string('term_key', 255);
            // One paragraph of plain text, at most `Glossary::MAX_DEFINITION_LENGTH` characters.
            $table->text('definition');
            $table->timestampsTz();

            // One entry per key per version. Leads with the tenant (ADR-0002 §D1).
            $table->unique(['tenant_id', 'form_version_id', 'term_key'], 'form_version_glossary_entries_unique');
        });

        DB::statement(
            'ALTER TABLE form_version_glossary_entries ADD CONSTRAINT form_version_glossary_entries_version_fk '
            .'FOREIGN KEY (tenant_id, form_version_id) REFERENCES form_versions (tenant_id, id) ON DELETE CASCADE'
        );

        withTenantIsolation('form_version_glossary_entries', 'draft_child');
    }

    public function down(): void
    {
        Schema::dropIfExists('form_version_glossary_entries');
    }
};
