<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How wide a section's page is drawn — M247 (`grid-wide-sticky-explorer`, `D231`): `wide` widens the respondent's page
 * while the section is on screen (the scanned-paper view's 90rem column), so a big table can be a table; NULL is today's
 * reading width. The builder stores anything else as NULL, so every section saved before this column keeps hashing
 * exactly as it did (`SchemaSnapshotSerializer` emits the key only when set).
 *
 * A typed column rather than a config bag, as `repeat_layout` is: every section attribute is one, and the CHECK says what
 * the value may be. No new policy: `form_sections`' `draft_child` policies cover a new column. Exported in a tenant
 * extract like every other section setting (`TenantExtractColumns` withholds nothing here).
 *
 * ⛔ ADDITIVE ONLY (`D137`): a nullable column with no default, nothing rewritten, no row touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_sections', function (Blueprint $table): void {
            $table->string('page_width', 10)->nullable()->after('repeat_layout');
        });

        DB::statement(
            'ALTER TABLE form_sections ADD CONSTRAINT form_sections_page_width_chk '
            ."CHECK (page_width IS NULL OR page_width = 'wide')"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE form_sections DROP CONSTRAINT IF EXISTS form_sections_page_width_chk');

        Schema::table('form_sections', function (Blueprint $table): void {
            $table->dropColumn('page_width');
        });
    }
};
