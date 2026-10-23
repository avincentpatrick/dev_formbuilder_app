<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a repeatable section is drawn — M185 (`R-0a7275ce`, `D129`): `table` lays it out as a table, every question a column
 * and every entry a row (Fillout's Table); NULL is today's cards, one per entry. `cards` is accepted and stored as NULL by
 * the builder, so every section saved before this column keeps hashing exactly as it did (`SchemaSnapshotSerializer`
 * emits the key only when set).
 *
 * A typed column rather than a config bag: every section attribute is one, and the CHECK says what the value may be.
 * No new policy: `form_sections`' `draft_child` policies cover a new column. Exported in a tenant extract like every other
 * section setting (`TenantExtractColumns` withholds nothing here).
 *
 * ⛔ ADDITIVE ONLY (`D137`): a nullable column with no default, nothing rewritten, no row touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_sections', function (Blueprint $table): void {
            $table->string('repeat_layout', 10)->nullable()->after('max_instances');
        });

        DB::statement(
            'ALTER TABLE form_sections ADD CONSTRAINT form_sections_repeat_layout_chk '
            ."CHECK (repeat_layout IS NULL OR repeat_layout IN ('cards', 'table'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE form_sections DROP CONSTRAINT IF EXISTS form_sections_repeat_layout_chk');

        Schema::table('form_sections', function (Blueprint $table): void {
            $table->dropColumn('repeat_layout');
        });
    }
};
