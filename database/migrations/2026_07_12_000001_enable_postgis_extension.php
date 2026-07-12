<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enable the PostGIS extension (Increment G5b1 / ADR-0006) so the geospatial field types can store a
 * geometry(Geometry, 4326) + GiST projection in `submission_geo_index` (created by the next migration).
 *
 * Runs on the privileged (superuser) connection — `CREATE EXTENSION postgis` is denied to the
 * NON-superuser `meridian_app` role the app connects as ("Must be superuser to create this extension").
 * Idempotent via IF NOT EXISTS: safe under migrate:fresh (this re-asserts the extension so a fresh DB is
 * self-contained) and on hosts where a DBA enabled it by hand first (the Windows-prod runbook path,
 * ADR-0006 Risks). For fresh Docker clusters the extension is also enabled by docker/postgres/init.
 *
 * Timestamped `000001` so it runs BEFORE `..._000002_create_submission_geo_index_table` — the geometry
 * column that migration adds requires the extension to already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_privileged')->statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function down(): void
    {
        // Intentionally NOT dropped. PostGIS installs shared catalog objects (spatial_ref_sys, types)
        // and other objects may come to depend on it; dropping an extension on a schema rollback is not
        // something we want, and `CREATE EXTENSION IF NOT EXISTS` on the next up() is a no-op anyway.
    }
};
