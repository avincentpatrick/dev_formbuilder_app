<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Increments an integer counter (e.g. `usage_count`) on a platform-global (NULL-tenant) row, on the elevated
 * `pgsql_privileged` (superuser) connection (Increment G9a).
 *
 * The nullable-global RLS shape keeps its WRITE policies strict (ADR-0002 §D2): a tenant-scoped connection can
 * never UPDATE a `tenant_id IS NULL` row. A naive `$platformRow->increment('usage_count')` on the app
 * connection therefore matches ZERO rows and returns 0 with NO error — so the onboarding gallery's "popular"
 * signal would silently never move for exactly the platform rows it curates. Routing platform-row bumps
 * through here (the same elevated path the seeder uses to author those rows) is what makes them actually move;
 * tenant-owned rows use the ordinary Eloquent `increment()`.
 */
final class PlatformRowCounter
{
    public function increment(string $table, string $id, string $column = 'usage_count'): void
    {
        DB::connection('pgsql_privileged')->table($table)->where('id', $id)->increment($column);
    }
}
