<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
 *
 * This is the ONLY request-reachable user of `pgsql_privileged`, a superuser connection that bypasses RLS
 * entirely — so the two things RLS would otherwise guarantee are enforced here by hand:
 *
 *  - `whereNull('tenant_id')` pins every write to a platform row IN THE QUERY, not just in a caller's PHP
 *    check. A caller that forgets to test `tenant_id === null` no-ops instead of writing across tenants.
 *  - {@see self::COUNTABLE} allowlists the table/column pairs reachable through here, so the elevated
 *    connection can never be pointed at a tenant-scoped table by a future call site.
 */
final class PlatformRowCounter
{
    /**
     * Table => the counter columns on it that may be bumped through the elevated connection. Deliberately
     * a closed list: every entry is a standing exception to "request code never touches pgsql_privileged".
     *
     * @var array<string, list<string>>
     */
    private const COUNTABLE = [
        'form_templates' => ['usage_count'],
    ];

    public function increment(string $table, string $id, string $column = 'usage_count'): void
    {
        if (! in_array($column, self::COUNTABLE[$table] ?? [], true)) {
            throw new InvalidArgumentException(
                "PlatformRowCounter refuses {$table}.{$column}: not an allowlisted platform counter."
            );
        }

        DB::connection('pgsql_privileged')
            ->table($table)
            ->where('id', $id)
            // Belt and braces: this connection bypasses RLS, so the platform-row invariant is enforced in
            // the query rather than trusted from the caller.
            ->whereNull('tenant_id')
            ->increment($column);
    }
}
