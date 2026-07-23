<?php

declare(strict_types=1);

use App\Support\Migrations\LegacyOverrideBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Merge-day backfill (ADR-0008 §D5, H5c): grandfather every then-existing tenant by stamping one
 * `legacy_overrides` row with the five ungated Phase-2 features. Zero merge-day regression is the acceptance
 * test. The full rationale — why this cannot run on the app connection, why the count guard uses `<` — lives
 * in {@see LegacyOverrideBackfill}, which also makes it testable by invoking the body directly.
 *
 * ── Why this is its own migration file ───────────────────────────────────────────────────────────────
 * Laravel wraps each migration in a transaction on `pgsql`, and the SEPARATE privileged session cannot see
 * the PRECEDING migration's uncommitted `CREATE TABLE legacy_overrides`. That is the binding constraint (the
 * G10a `backfill_resource_grants_from_form_collaborators` precedent), not operator convenience.
 *
 * On `migrate:fresh` the database is empty at this point, so nothing is stamped — new/seeded tenants are born
 * gated, which is correct. The backfill only does real work against a live, populated database on deploy.
 */
return new class extends Migration
{
    /**
     * The write goes to `pgsql_privileged` in AUTOCOMMIT, so the transaction Laravel opens on the DEFAULT
     * connection would roll back nothing here — leaving it on would only create the illusion of atomicity.
     * A thrown guard aborts the migration RUN, not the INSERT; recovery is fail-forward (the INSERT is
     * idempotent via ON CONFLICT DO NOTHING).
     */
    public $withinTransaction = false;

    public function up(): void
    {
        (new LegacyOverrideBackfill)(DB::connection('pgsql_privileged'));
    }

    public function down(): void
    {
        // The backfilled rows; the DDL migration's down() drops the table right after, so this is belt-and-braces.
        DB::connection('pgsql_privileged')->statement('TRUNCATE legacy_overrides');
    }
};
