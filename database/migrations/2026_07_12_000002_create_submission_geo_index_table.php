<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The PostGIS geometry projection (Increment G5b1 / ADR-0006 D1) — one row per top-level geospatial
 * field per submission, written transactionally alongside the JSONB answer document in the pipeline's
 * persist stage (the Risk R1 atomicity guarantee, extended to geometry). The canonical answer stays the
 * GeoJSON envelope in `submission_answers.answers`; this table is the GiST-indexed spatial projection
 * that unlocks `ST_*` distance/containment/validity queries and map dashboards (the reason ADR-0001
 * chose Postgres). Mirrors `submission_answer_index`: the JSONB is the source of truth, this is derived.
 *
 * Geo answers are object-valued and are NEVER projected into the scalar `submission_answer_index`
 * (that projector skips arrays/objects); this is their dedicated typed projection. Only top-level geo is
 * projected — geo inside a repeatable section is banned at publish (StructuralValidationGate), so the
 * one-row-per-field-per-submission shape holds.
 *
 * PK is bigint identity (a pure internal projection row, never addressed externally). Carries `tenant_id`
 * → RLS `strict` (and the migration linter requires the isolation call for any tenant_id-bearing table).
 * The `geom` column + GiST index are added via raw SQL because Blueprint has no PostGIS geometry type.
 * Requires the postgis extension (enabled by the `..._000001_enable_postgis_extension` migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_geo_index', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('submission_id')->constrained('submissions')->cascadeOnDelete();
            $table->foreignUuid('form_version_id')->constrained('form_versions')->cascadeOnDelete();
            $table->foreignUuid('form_field_id')->constrained('form_fields')->cascadeOnDelete();
            $table->string('field_key', 150); // denormalized form_fields.key — stable across versions

            $table->string('geometry_type', 20);         // 'Point' | 'LineString' | 'Polygon'
            $table->decimal('captured_accuracy', 10, 3)->nullable(); // metres, from the GeoJSON `accuracy` member

            $table->timestampsTz();

            $table->unique(['submission_id', 'form_field_id']); // one projection per geo field per submission
            $table->index(['tenant_id', 'field_key']);
        });

        // Blueprint has no PostGIS geometry type — add the SRID-4326 geometry column and its GiST index
        // by hand. `Geometry` (not `Point`) so one table holds all three geo types (point/line/polygon).
        DB::statement('ALTER TABLE submission_geo_index ADD COLUMN geom geometry(Geometry, 4326) NOT NULL');
        DB::statement('CREATE INDEX submission_geo_index_geom_gist ON submission_geo_index USING GIST (geom)');

        withTenantIsolation('submission_geo_index');
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_geo_index');
    }
};
