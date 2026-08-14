<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a Google account attaches to a local identity (Increment J3c2, ADR-0017 §D1).
 *
 * ── A COLUMN, NOT A `social_identities` TABLE, AND THE REASON IS RLS RATHER THAN TASTE ────────────────
 * Any check for an existing account must resolve on the `pgsql_auth` connection: as `meridian_app` with
 * no user context the fail-closed join-shape policy on `users` HIDES an existing row, so a duplicate
 * surfaces as a raw unique-index 500 rather than a clean outcome (`CreateNewUser` documents this for the
 * email). A column on `users` rides the existing table-level `GRANT SELECT, UPDATE ON users TO
 * meridian_auth` and the existing `TO meridian_auth` write policy for free. A second table would need a
 * NEW grant and a NEW policy on the most sensitive role in the deployment, to buy generality for a second
 * provider that scope has explicitly ruled out. Converting later is one table plus a mechanical backfill.
 *
 * ⚠️ NO `withTenantIsolation()`, AND THAT IS CORRECT RATHER THAN AN OMISSION: this migration creates no
 * table and `users` is not tenant-scoped — its visibility is the join-shape policy applied by
 * `2026_07_05_000102_apply_users_rls.php`, not a `tenant_id` column. `scripts/migration-lint.php` only
 * requires the call for a CREATE carrying a literal `tenant_id`, so it is silent here by design.
 *
 * ⚠️ UNIQUE, because the whole point is that one Google account is one person here. Without it, two local
 * rows could claim the same `sub` and the resolution order would decide who you signed in as.
 *
 * NULLABLE and with no default, because the overwhelming majority of accounts will never have one: the
 * column's absence is the normal state, and `NULL` is what "this account has no Google identity attached"
 * means everywhere it is read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('google_id', 255)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['google_id']);
            $table->dropColumn('google_id');
        });
    }
};
