<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each person has seen of What's new — M181 (`R-c7247dbf`, `D126` A, `D157` A; RBAC doc §6).
 *
 * The newest release (`releases.version`) this person closed the What's new prompt on. Any close marks it seen
 * (`D126` A) through `POST /whats-new/seen`, which only ever moves it forward. NULL is "has never seen one", and such a
 * person is shown every release since they joined, at least the newest (`D157` A) — so NO backfill: stamping today's
 * accounts would hide the first release from exactly the people it was written for.
 *
 * No `withTenantIsolation()` and no new policy: `users` already carries FORCE row security, and its `app_update` policy
 * (`TenantIsolation::usersWritePoliciesSql`) covers a new column. The write runs inside the tenant route group, where
 * `EstablishTenantDatabaseContext` has set the request's own user GUC — without it an UPDATE lands zero rows silently.
 * Withheld from tenant extracts like `welcomed_at` (`TenantExtractColumns`).
 *
 * ⛔ ADDITIVE ONLY (`D137`): a nullable column, no default, nothing rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('whats_new_seen_version', 32)->nullable()->after('welcomed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('whats_new_seen_version');
        });
    }
};
