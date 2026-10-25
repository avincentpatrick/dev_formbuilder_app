<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Philippine places — M186 (`R-70c7673a`, `D131`; data-dictionary §41).
 *
 * The Philippine Standard Geographic Code as the Philippine Statistics Authority publishes it each quarter, normalised to
 * the four levels a Cascading select offers: region · province or district · city or municipality · barangay. The rows
 * are built from the PSA workbook into `database/data/psgc/places.csv` (`psgc:build`) and copied here by every deploy
 * (`psgc:sync`, an upsert by level and code that never deletes). Publishing a cascade bound to them writes the choices
 * into the version (`App\Services\Forms\PsgcLists`), so a published form keeps its quarter.
 *
 * ── NO `tenant_id`, NO RLS ─────────────────────────────────────────────────────────────────────────────────
 * The country's places are the same for every workspace — a shared lookup under ADR-0002 D1, like `releases`; there is
 * nothing to isolate. `scripts/migration-lint.php` and `TenantScopedTables` check only tables carrying `tenant_id`.
 *
 * A city outside any province is a row at BOTH the province and the city level — the same code, the same name — so every
 * barangay has a parent one level up; `(level, code)` is therefore the natural key, not `code`. `publication` is the
 * newest quarter that carried the row (`2026Q3`); a place the PSA recoded keeps its old one and drops out of the next
 * publish without being deleted.
 *
 * ⛔ ADDITIVE ONLY (`D137`): a new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('psgc_places', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('level', 16);
            $table->string('code', 10);
            $table->string('name', 255);
            $table->string('parent_code', 10)->nullable();
            $table->string('publication', 6);
            $table->timestampsTz();

            $table->unique(['level', 'code']);
            $table->index(['publication', 'level']);
        });

        DB::statement(
            'ALTER TABLE psgc_places ADD CONSTRAINT psgc_places_level_check '
            ."CHECK (level IN ('region', 'province', 'city', 'barangay'))"
        );
        DB::statement(
            'ALTER TABLE psgc_places ADD CONSTRAINT psgc_places_code_check '
            ."CHECK (code ~ '^[0-9]{10}$' AND (parent_code IS NULL OR parent_code ~ '^[0-9]{10}$'))"
        );
        DB::statement(
            'ALTER TABLE psgc_places ADD CONSTRAINT psgc_places_publication_check '
            ."CHECK (publication ~ '^[0-9]{4}Q[1-4]$')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('psgc_places');
    }
};
