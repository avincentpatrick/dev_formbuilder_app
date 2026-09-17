<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The connection topology, stated (Phase 4, P2a — ADR-0017 §D1).
|--------------------------------------------------------------------------
| This application has FOUR live PostgreSQL connections and they are a ROLE separation, not a database
| separation: `pgsql` (meridian_app, owns the tables so FORCE RLS binds it), `pgsql_auth` (pre-auth; reads
| `users`, and since M8 also reads `tenant_users` — SELECT-only, for the invitation identity check),
| `pgsql_privileged` (superuser, platform rows), `pgsql_superadmin` (still RLS-subject,
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

it('pins the session time zone on every live connection, whatever the server default', function (string $name): void {
    // WHY THIS IS A BEHAVIOUR TEST AND NOT A CONFIG ASSERTION. The application writes timestamps with no
    // offset — `Grammar::getDateFormat()` is 'Y-m-d H:i:s' and `Connection::prepareBindings()` formats every
    // DateTimeInterface binding with it — so PostgreSQL resolves each write in the SESSION time zone. On the
    // Windows testing server that zone was Asia/Manila, and every value bound from PHP landed eight hours
    // early: password-reset links were expired on arrival, and their 60-second throttle never tripped.
    // `PostgresConnector::configureTimezone()` issues `set time zone` only when the connection config carries
    // a `timezone` key, so the key is what makes the write zone and the read zone the same thing.
    //
    // ⚠️ THIS TEST HAS TO MANUFACTURE A NON-UTC SERVER, or it proves nothing: CI's PostgreSQL already reports
    // UTC, so a bare assertion passes with or without the fix. `PGTZ` is libpq's startup time zone, which
    // beats the database and role defaults and is beaten in turn by the connector's own `set time zone` —
    // exactly the precedence this test is about. It is set around a PROBE COPY of the connection so that
    // RefreshDatabase's open transaction on `pgsql` is never touched, and so nothing on the server changes.
    $probe = "tz_probe_{$name}";
    $previous = getenv('PGTZ');

    config(["database.connections.{$probe}" => config("database.connections.{$name}")]);
    putenv('PGTZ=Asia/Manila');

    try {
        $instant = CarbonImmutable::parse('2026-09-18 02:00:00', 'UTC');

        $row = DB::connection($probe)->selectOne(
            'select current_setting(?) as zone, extract(epoch from ?::timestamptz)::bigint as epoch',
            ['TimeZone', $instant],
        );

        expect((string) $row->zone)->toBe(config('app.timezone'), sprintf(
            'Connection `%s` opened in the server\'s own time zone (%s) rather than the application\'s. Add '
            ."'timezone' => 'UTC' to it in config/database.php: a database-level or role-level default is not "
            .'enough, because a restore without --create drops it and a fresh initdb takes the host zone.',
            $name,
            (string) $row->zone,
        ));

        expect((int) $row->epoch)->toBe($instant->getTimestamp(), sprintf(
            'Connection `%s` stored an offset-less UTC timestamp as a different instant — the eight-hour skew '
            .'measured on the testing server. What is written and what is read back must be the same moment.',
            $name,
        ));
    } finally {
        DB::purge($probe);
        putenv($previous === false ? 'PGTZ' : "PGTZ={$previous}");
        config(["database.connections.{$probe}" => null]);
    }
})->with(liveConnectionNames());

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
