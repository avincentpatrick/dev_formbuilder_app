<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The connection topology, stated (Phase 4, P2a — ADR-0017 §D1).
|--------------------------------------------------------------------------
| This application has FOUR live PostgreSQL connections and they are a ROLE separation, not a database
| separation: `pgsql` (meridian_app, owns the tables so FORCE RLS binds it), `pgsql_auth` (pre-auth, reads
| `users` only), `pgsql_privileged` (superuser, platform rows), `pgsql_superadmin` (still RLS-subject,
| reaches cross-tenant rows only through the role-scoped carve-out policies). All four point at the SAME
| database and the SAME `public` schema.
|
| Nothing in the suite said so, and the entire isolation design depends on it. Several cross-table RLS
| policies subquery a neighbouring table — `users` reads `tenant_users`, `resource_grants` reads
| `forms`/`scope_nodes`, the draft-child guard reads `form_versions` — and PostgreSQL has no cross-DATABASE
| subquery, so those policies are expressible only while co-location holds.
|
| Repointing one connection at a different database is therefore not a configuration tweak; it is a change
| to what the isolation model can express. This file makes that a decision somebody takes deliberately
| rather than a line somebody edits.
*/

/** @return list<string> */
function liveConnectionNames(): array
{
    return ['pgsql', 'pgsql_auth', 'pgsql_privileged', 'pgsql_superadmin'];
}

it('points every live connection at the same database', function (): void {
    $names = liveConnectionNames();

    $databases = array_map(
        static fn (string $c): string => (string) DB::connection($c)->getDatabaseName(),
        $names,
    );

    expect(array_unique($databases))->toHaveCount(1, sprintf(
        'The four live connections no longer share one database: %s. Several RLS policies subquery a '
        .'neighbouring table, and PostgreSQL cannot express a cross-database subquery — so this is a change '
        .'to the isolation model, not to configuration. See ADR-0017.',
        json_encode(array_combine($names, $databases)),
    ));
});

it('points every live connection at the same schema', function (): void {
    // `search_path` is hard-coded to `public` on all four. It matters for the same reason the database does:
    // a policy created under one search_path binds its referenced tables by OID, so moving a connection to
    // a different schema silently changes which table a policy is talking about.
    $paths = array_map(
        static fn (string $c): mixed => config("database.connections.{$c}.search_path"),
        liveConnectionNames(),
    );

    expect(array_unique($paths))->toBe(['public']);
});

it('uses four distinct roles, which is what the separation actually is', function (): void {
    // The corollary of the two assertions above: if the databases are identical, the ONLY thing
    // distinguishing these connections is the role — and therefore what RLS does to them. Two connections
    // collapsing onto one role would silently erase a privilege boundary while every query kept working.
    $users = array_map(
        static fn (string $c): mixed => config("database.connections.{$c}.username"),
        liveConnectionNames(),
    );

    expect(array_unique($users))->toHaveCount(4);
});

it('keeps the application connection subject to row-level security', function (): void {
    // The load-bearing half of the role separation. RLS is ignored for a superuser or a BYPASSRLS role, so
    // if the default connection ever authenticated as one, every isolation test in the suite would pass
    // vacuously — including the cross-tenant fuzz pack.
    $role = DB::selectOne(
        'select rolsuper::int as is_super, rolbypassrls::int as bypass_rls
         from pg_roles where rolname = current_user'
    );

    expect((int) $role->is_super)->toBe(0);
    expect((int) $role->bypass_rls)->toBe(0);
});
