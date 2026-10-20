<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The release history — M181 (`R-c7247dbf`, `D125` C; data-dictionary §40).
 *
 * The user (2026-10-15): *"i want it saved in the database. so every release, we know what changed, what version it is"*.
 * The notes are written with each change in `database/data/releases.json`; every deploy copies them here
 * (`releases:sync`, upsert by version, never a delete), and the What's new prompt and page read this table.
 *
 * ── NO `tenant_id`, NO RLS ─────────────────────────────────────────────────────────────────────────────────
 * A release is the platform's, the same for every workspace, like `plans` and `platform_api_tokens`; there is nothing
 * to isolate. `scripts/migration-lint.php` and `TenantScopedTables` check only tables carrying `tenant_id`.
 *
 * `version` is unique and held to three numbers by a CHECK (the shape `BuildInfo::SEMVER` reads from `VERSION`), so
 * ordering by `version_compare()` is always defined. `released_at` is stamped by the sync that first copies a release —
 * the moment it went live on that server — and never rewritten; the file carries no date for that reason.
 *
 * ⛔ ADDITIVE ONLY (`D137`): a new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('version', 32)->unique();
            $table->string('title', 255);
            $table->jsonb('items');
            $table->timestampTz('released_at');
            $table->timestampsTz();
        });

        DB::statement(
            'ALTER TABLE releases ADD CONSTRAINT releases_version_check '
            ."CHECK (version ~ '^[0-9]+[.][0-9]+[.][0-9]+$')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
