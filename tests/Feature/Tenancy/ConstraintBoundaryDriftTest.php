<?php

declare(strict_types=1);

use App\Support\Tenancy\ConstraintBoundaries;
use App\Support\Tenancy\ExtractionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| ADR-0002 §D5 vs. the database (Phase 4, P2c).
|--------------------------------------------------------------------------
| §D5: "No foreign key, unique constraint, or index may span tenant boundaries", because a global
| constraint "would become a real obstacle to the future per-tenant extraction path". P2b built that
| path, so this file is the other half of App\Support\Tenancy\ConstraintBoundaries — the constant
| carries the reasoning, these assertions carry the drift.
|
| Raw `DB::select` against the catalogs throughout, never Eloquent or Schema:: — what is being proven
| is the DATABASE's configuration, and a Blueprint-level answer would describe the migration's
| intent rather than what PostgreSQL actually enforces. Same argument as
| TenantTableClassificationDriftTest, from which the unique-index case below was moved.
|
| ⚠️ THE SWEEPS KEY ON COLUMNS, NOT ON DEFINITION TEXT. The unique case previously lived next door
| filtering `pg_get_indexdef(...) not like '%tenant_id%'`, which matches an index's WHERE clause as
| readily as its key: `settings_platform_key_unique` is `ON settings (key) WHERE (tenant_id IS NULL)`
| and was invisible to it. Expanding `indkey`/`conkey` through `unnest ... with ordinality` — the
| idiom ExtractSchema::read() already uses — is what makes the question "is the tenant in the KEY".
|
| ⚠️ RI CHECKS BYPASS RLS. PostgreSQL: "referential integrity checks, such as unique or primary key
| constraints and foreign key references, always bypass row security." So neither of these sweeps is
| asking a question RLS has already answered — a unique index refuses a value because of a row the
| tenant cannot see, and a foreign key accepts a reference to one.
|
| Helper names are prefixed `boundary*` deliberately: Pest loads every file in this directory into
| one process, and `tenantIdCarryingTables()` / `catalogColumnsOf()` are already declared at file
| scope by the two sibling drift tests. A same-named helper here is a fatal redeclaration.
*/

/**
 * Unique, non-primary indexes on a `tenant_id`-carrying table whose KEY does not include `tenant_id`.
 *
 * @return list<string>
 */
function boundaryCrossingUniqueIndexes(): array
{
    /** @var list<object{idx: string}> $rows */
    $rows = DB::select(
        "select i.indexrelid::regclass::text as idx
         from pg_index i
         join pg_class c on c.oid = i.indrelid
         join pg_namespace n on n.oid = c.relnamespace
         where n.nspname = 'public'
           and i.indisunique
           and not i.indisprimary
           and exists (
             select 1 from pg_attribute ta
             where ta.attrelid = c.oid and ta.attname = 'tenant_id'
               and ta.attnum > 0 and not ta.attisdropped
           )
           and not exists (
             select 1
             from unnest(i.indkey) with ordinality as k(attnum, ord)
             join pg_attribute a on a.attrelid = c.oid and a.attnum = k.attnum
             where a.attname = 'tenant_id'
               and k.ord <= i.indnkeyatts
           )"
    );

    return boundarySorted(array_map(static fn (object $r): string => (string) $r->idx, $rows));
}

/**
 * Foreign keys pointing at a `tenant_id`-carrying table whose key does not include `tenant_id`.
 *
 * The predicate is about the TARGET, not about both sides: a pivot with no tenant column of its own
 * (`role_has_permissions`) and `tenants` itself both point into tenant-scoped tables, and a
 * both-sides sweep drops them. FKs into `tenants` and `users` fall out for free — neither carries
 * `tenant_id`, so neither has a boundary to cross.
 *
 * @return list<string>
 */
function boundaryCrossingForeignKeys(): array
{
    /** @var list<object{conname: string}> $rows */
    $rows = DB::select(
        "select con.conname
         from pg_constraint con
         join pg_class src on src.oid = con.conrelid
         join pg_class tgt on tgt.oid = con.confrelid
         join pg_namespace n on n.oid = src.relnamespace
         where con.contype = 'f'
           and n.nspname = 'public'
           and exists (
             select 1 from pg_attribute ta
             where ta.attrelid = tgt.oid and ta.attname = 'tenant_id'
               and ta.attnum > 0 and not ta.attisdropped
           )
           and not exists (
             select 1
             from unnest(con.conkey) with ordinality as k(attnum, ord)
             join pg_attribute a on a.attrelid = src.oid and a.attnum = k.attnum
             where a.attname = 'tenant_id'
           )"
    );

    return boundarySorted(array_map(static fn (object $r): string => (string) $r->conname, $rows));
}

/**
 * Sorted in PHP, never in SQL. The database's `order by` uses the en_US.utf8 collation, which does not
 * weight underscores the way a byte comparison does, so an SQL-ordered expectation would be pinned to
 * the collation of whichever cluster ran it.
 *
 * @param  list<string>  $names
 * @return list<string>
 */
function boundarySorted(array $names): array
{
    sort($names);

    return $names;
}

it('runs as a role that is actually subject to RLS, against a catalog that is actually populated', function (): void {
    // Two ways this whole file passes for the wrong reason, and neither is visible in a green run.
    //
    // A BYPASSRLS or superuser role would not change either sweep's answer — both read catalogs, not
    // rows — but it WOULD mean the schema under test is not the one the application sees, and every
    // reason in ConstraintBoundaries is written about the application's connection. Reused rather than
    // rewritten: ExtractionGuard is P2b's, and it throws rather than returning a boolean.
    ExtractionGuard::assertRlsSubjectRole();

    // And an empty schema satisfies "no unrecorded boundary crossings" perfectly, as does a half-migrated
    // one — the sweeps would simply not see the table whose constraint is missing.
    //
    // ⚠️ COMPARED AGAINST THE MIGRATION FILES, NOT AGAINST A REMEMBERED TABLE COUNT. The first draft of
    // this case pinned `55 base tables` from PROGRESS.md and failed at 56: J3c2 had added
    // `google_auth_requests`, and the psql census this increment was planned from had been taken against
    // a `meridian_testing` two migrations stale, so several of its numbers described a schema nobody
    // runs. A hardcoded count reddens for every new table while proving nothing about isolation; this
    // form asks the question actually worth asking — is the schema under test fully migrated — and
    // maintains itself.
    $ran = DB::selectOne('select count(*)::int as n from migrations');
    $files = glob(database_path('migrations/*.php'));

    expect((int) $ran->n)->toBe(count($files === false ? [] : $files));
});

it('records every unique index whose key crosses a tenant boundary', function (): void {
    // The reverse direction, and the one that earns the file's keep: a migration adding a bare unique
    // to a tenant-scoped table reddens here the moment it lands, and the author has to write down why
    // rather than discover it during an extraction two quarters later.
    $unrecorded = array_values(array_diff(
        boundaryCrossingUniqueIndexes(),
        ConstraintBoundaries::indexNames(),
    ));

    expect($unrecorded)->toBe([], sprintf(
        'These unique indexes sit on a tenant-scoped table without `tenant_id` in the KEY and are not in '
        .'ConstraintBoundaries::UNIQUE_EXCEPTIONS: %s. Add `tenant_id` to the index, or add the index there '
        .'with a rationale somebody could act on (ADR-0002 §D5).',
        implode(', ', $unrecorded),
    ));
});

it('excepts no unique index that has stopped crossing one', function (): void {
    // The forward direction. A stale entry is not untidy, it is a claim that something was reasoned
    // about which no longer exists — P2b's rename-guard argument, where the ABSENCE is the defect.
    $stale = array_values(array_diff(
        ConstraintBoundaries::indexNames(),
        boundaryCrossingUniqueIndexes(),
    ));

    expect($stale)->toBe([], sprintf(
        'ConstraintBoundaries::UNIQUE_EXCEPTIONS names these, but the catalog has no such boundary-crossing '
        .'unique index: %s. Either it was dropped, or it was renamed and the exception now covers nothing.',
        implode(', ', $stale),
    ));
});

it('records every foreign key that can reference across a tenant boundary', function (): void {
    // The FK half has never been measured before this increment, and it is the half §D5 names first.
    $unrecorded = array_values(array_diff(
        boundaryCrossingForeignKeys(),
        ConstraintBoundaries::foreignKeyNames(),
    ));

    expect($unrecorded)->toBe([], sprintf(
        'These foreign keys point at a tenant-scoped table without carrying `tenant_id`, so PostgreSQL will '
        .'accept a cross-tenant reference and act on it: %s. Declare the composite '
        .'`foreign([tenant_id, x_id])->references([tenant_id, id])` shape — nine constraints already do — or '
        .'add each to ConstraintBoundaries::FOREIGN_KEY_EXCEPTIONS with a rationale (ADR-0002 §D5).',
        implode(', ', $unrecorded),
    ));
});

it('excepts no foreign key that has stopped crossing one', function (): void {
    $stale = array_values(array_diff(
        ConstraintBoundaries::foreignKeyNames(),
        boundaryCrossingForeignKeys(),
    ));

    expect($stale)->toBe([], sprintf(
        'ConstraintBoundaries::FOREIGN_KEY_EXCEPTIONS names these, but the catalog has no such '
        .'boundary-crossing foreign key: %s. A constraint made composite must lose its exception in the '
        .'same commit, or the list starts describing a schema nobody has.',
        implode(', ', $stale),
    ));
});

it('gives every exception a reason somebody could act on', function (): void {
    // ⚠️ COLLECTED, NOT ASSERTED PER ENTRY. Written as an expect() inside the loop this stops at the
    // first offender, so a run reports one, the author fixes it, and the next run reports the next.
    // P2b's census gate hid its second finding behind its first exactly this way; a gate that reveals
    // its findings one per run teaches people to distrust its green.
    //
    // The 60-character floor is crude on purpose: it catches "legacy", "pre-existing" and "safe", which
    // are labels, not reasons. It cannot catch a long sentence that says nothing, and does not try.
    $thin = [];

    foreach ([
        'UNIQUE_EXCEPTIONS' => ConstraintBoundaries::UNIQUE_EXCEPTIONS,
        'FOREIGN_KEY_EXCEPTIONS' => ConstraintBoundaries::FOREIGN_KEY_EXCEPTIONS,
    ] as $listName => $entries) {
        foreach ($entries as $name => $reason) {
            if (mb_strlen($reason) <= 60) {
                $thin[] = "{$listName}[{$name}] (".mb_strlen($reason).' chars)';
            }
        }
    }

    expect($thin)->toBe([], 'These exceptions have a label where a reason belongs: '.implode(', ', $thin));
});

it('keeps the composite shape it recommends, so the remedy in every failure message is real', function (): void {
    // The nine constraints that DO carry the tenant. Without this the file only ever describes what is
    // wrong: a reader hitting the FK message above would have no evidence the shape it prescribes is
    // expressible on this schema, and the parent-side `(tenant_id, id)` unique it depends on is itself
    // a thing a migration could drop.
    //
    // ⚠️ `array_length(conkey, 1) > 1` IS LOAD-BEARING, not a tidy-up. Without it this case matches all
    // 39 single-column `x.tenant_id -> tenants.id` constraints, which contain `tenant_id` in their key
    // and are not composite at all — the assertion would then be pinned to a list that grows with every
    // new tenant-scoped table while saying nothing about the shape it exists to demonstrate.
    /** @var list<object{conname: string}> $rows */
    $rows = DB::select(
        "select con.conname
         from pg_constraint con
         join pg_class src on src.oid = con.conrelid
         join pg_namespace n on n.oid = src.relnamespace
         where con.contype = 'f'
           and n.nspname = 'public'
           and array_length(con.conkey, 1) > 1
           and exists (
             select 1
             from unnest(con.conkey) with ordinality as k(attnum, ord)
             join pg_attribute a on a.attrelid = src.oid and a.attnum = k.attnum
             where a.attname = 'tenant_id'
           )"
    );

    expect(boundarySorted(array_map(static fn (object $r): string => (string) $r->conname, $rows)))->toBe([
        'connection_subscriptions_connection_fk',
        'connection_subscriptions_form_fk',
        'forms_scope_node_fk',
        'scope_nodes_parent_fk',
        'sso_auth_failures_connection_fk',
        'sso_auth_requests_connection_fk',
        'webhook_deliveries_endpoint_fk',
        'webhook_deliveries_subscription_fk',
        'webhook_endpoints_form_fk',
    ]);
});
