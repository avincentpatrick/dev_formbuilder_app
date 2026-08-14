<?php

declare(strict_types=1);

use App\Enums\TenantTableClass;
use App\Support\Tenancy\TenantScopedTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The classification constant vs. the database (Phase 4, P2a — ADR-0017 §D3).
|--------------------------------------------------------------------------
| App\Support\Tenancy\TenantScopedTables holds a DECISION about every table: does a tenant-scoped read
| reach it, and does it return exactly the tenant's rows or a superset? This file is the other half of
| that idiom — the constant carries the reasoning, and these assertions carry the drift.
|
| Raw `DB::select` against the catalogs throughout, never Eloquent: what is being proven is the
| DATABASE's configuration, and the BelongsToTenant global scope would make an ORM-based version of
| this file pass with RLS switched off entirely. Same argument as CrossTenantIsolationTest.
|
| ⚠️ THE REVERSE DIRECTION IS THE ONE THAT EARNS ITS KEEP. Asserting that everything on the list is
| protected catches a policy being dropped. Asserting that everything protected is ON the list catches
| the failure that has actually happened here: a table shipping with `tenant_id` and no isolation.
| `domains` is that table today — deliberately, with a reason — and it was discovered by this sweep
| rather than remembered.
|
| ⚠️ pdo_pgsql hands booleans back as the STRINGS 't'/'f', and `(bool) 'f' === true`. Every boolean
| below is cast to int IN SQL, on CrossTenantIsolationTest's precedent.
*/

/** @return list<string> */
function tenantIdCarryingTables(): array
{
    /** @var list<object{table_name: string}> $rows */
    $rows = DB::select(
        "select table_name from information_schema.columns
         where table_schema = 'public' and column_name = 'tenant_id'
         order by table_name"
    );

    return array_map(static fn (object $r): string => (string) $r->table_name, $rows);
}

it('runs as a role that is actually subject to RLS', function (): void {
    // Without this the whole file passes vacuously: a superuser or BYPASSRLS role sees every row of
    // every table regardless of policy, so "is this table protected?" stops being a real question.
    $role = DB::selectOne(
        'select rolsuper::int as is_super, rolbypassrls::int as bypass_rls
         from pg_roles where rolname = current_user'
    );

    expect((int) $role->is_super)->toBe(0);
    expect((int) $role->bypass_rls)->toBe(0);
});

it('protects every table the constant claims a tenant-scoped read reaches', function (string $table): void {
    // ENABLE alone is not enough: the app role OWNS these tables, and an owner bypasses RLS unless
    // FORCE is set. Dropping FORCE is therefore invisible to every ordinary feature test.
    $meta = DB::selectOne(
        'select relrowsecurity::int as enabled, relforcerowsecurity::int as forced
         from pg_class where relname = ?',
        [$table]
    );

    expect($meta)->not->toBeNull("`{$table}` is on the classification list but is not a table.");
    expect((int) $meta->enabled)->toBe(1, "`{$table}` has RLS disabled.");
    expect((int) $meta->forced)->toBe(1, "`{$table}` does not FORCE RLS, so the owning app role bypasses it.");

    $policies = DB::selectOne(
        "select count(*) as n from pg_policies
         where schemaname = 'public' and tablename = ? and cmd = 'SELECT'
           and qual like '%current_tenant_id%'",
        [$table]
    );

    expect((int) $policies->n)->toBeGreaterThan(0, "`{$table}` has no tenant-keyed SELECT policy.");
})->with(TenantScopedTables::all());

it('classifies every table that carries tenant_id, so a new one cannot ship unnoticed', function (): void {
    // The reverse sweep. A migration that adds a tenant-scoped table reddens this the moment it lands,
    // which is a stronger guarantee than scripts/migration-lint.php gives: that linter keys on
    // Schema::create and is blind to a table created any other way, or altered to add the column later.
    $unclassified = array_values(array_diff(
        tenantIdCarryingTables(),
        TenantScopedTables::everyTenantIdCarrier(),
    ));

    expect($unclassified)->toBe([], sprintf(
        'These tables carry `tenant_id` but appear in no list in TenantScopedTables: %s. '
        .'Add each to STRICT, NULLABLE_GLOBAL, or UNPROTECTED_TENANT_TABLES with a rationale.',
        implode(', ', $unclassified),
    ));
});

it('lists no table that has stopped carrying tenant_id', function (): void {
    // The other direction of the same sweep: a dropped column would otherwise leave a name on the list
    // asserting a property the table no longer has.
    $stale = array_values(array_diff(
        TenantScopedTables::everyTenantIdCarrier(),
        tenantIdCarryingTables(),
    ));

    expect($stale)->toBe([], 'Listed but no longer carrying `tenant_id`: '.implode(', ', $stale));
});

it('sees the widening disjunct on exactly the platform-shared tables', function (): void {
    // `OR tenant_id IS NULL` is what makes a read return the platform catalog alongside the tenant's own
    // rows. Applying it to a table that should be strict is the highest-consequence single-word mistake
    // available in a migration, and it is invisible to every single-tenant feature test.
    /** @var list<object{tablename: string}> $rows */
    $rows = DB::select(
        "select distinct tablename from pg_policies
         where schemaname = 'public' and cmd = 'SELECT'
           and qual like '%current_tenant_id%' and qual like '%IS NULL%'
         order by tablename"
    );

    $widened = array_map(static fn (object $r): string => (string) $r->tablename, $rows);

    expect($widened)->toBe(TenantScopedTables::NULLABLE_GLOBAL);
});

it('keeps the append-only ledger strict, despite its nullable column', function (string $table): void {
    // audits and personal_access_tokens both carry a NULLABLE tenant_id, which is exactly what a
    // nullability-based classification would read as "platform-shared". TenantIsolation refuses that
    // widening for `audits` in as many words — it "would leak it" — and the audit-compliance spec agrees:
    // a tenant must not be able to read the operator's platform rows.
    expect(TenantScopedTables::classify($table))->toBe(TenantTableClass::TenantScoped);
    expect(TenantScopedTables::rlsReturnsSuperset($table))->toBeFalse();

    $nullable = DB::selectOne(
        "select is_nullable from information_schema.columns
         where table_schema = 'public' and table_name = ? and column_name = 'tenant_id'",
        [$table]
    );

    // The premise this case is defending. If the column ever becomes NOT NULL the trap disappears and
    // this case stops being about anything — which is worth noticing rather than silently keeping.
    expect((string) $nullable->is_nullable)->toBe('YES');
})->with(['audits', 'personal_access_tokens']);

it('finds no unpinned global unique constraint or index on a tenant-scoped table', function (): void {
    // ADR-0002 §D5: "No foreign key, unique constraint, or index may span tenant boundaries", because a
    // global constraint is a real obstacle to the per-tenant extraction path this row exists to enable.
    // Nothing has ever checked it.
    //
    // ⚠️ SCANS pg_index, NOT pg_constraint. Laravel emits a PARTIAL unique (`->unique()->where(...)`) as a
    // bare index with no constraint row, so a pg_constraint-only sweep silently misses three of the ten
    // below — and misses precisely the ones a reviewer is least likely to spot by eye.
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
             select 1 from information_schema.columns ic
             where ic.table_schema = 'public' and ic.table_name = c.relname
               and ic.column_name = 'tenant_id'
           )
           and pg_get_indexdef(i.indexrelid) not like '%tenant_id%'"
    );

    // Sorted in PHP, not in SQL. The database's `order by` uses the en_US.utf8 collation, which does not
    // weight underscores the way a byte comparison does — so an SQL-ordered expectation would be pinned to
    // the collation of whichever cluster ran it.
    $found = array_map(static fn (object $r): string => (string) $r->idx, $rows);
    sort($found);

    // Each of these is globally unique for a stated reason; none is an accident.
    expect($found)->toBe([
        // A hostname and a DNS challenge token are globally unique BY DEFINITION — two tenants cannot
        // both own `forms.acme.com`. Scoping either per tenant would make the uniqueness meaningless.
        'domains_domain_unique',
        'domains_verification_token_unique',
        // Secrets. A token that collides across tenants is a cross-tenant authentication bug, so global
        // uniqueness is the property being bought, not a boundary violation.
        'impersonation_tokens_token_hash_unique',
        'permissions_name_guard_name_unique',
        'personal_access_tokens_token_unique',
        // The SAML `InResponseTo` this SP mints. An IdP correlates on it with no notion of our tenants.
        'sso_auth_requests_request_id_unique',
        // Transitively tenant-safe: both columns are FKs to tenant-scoped tables, so a collision across
        // tenants is unreachable without a prior isolation failure upstream.
        'submission_answer_index_submission_id_form_field_id_unique',
        'submission_geo_index_submission_id_form_field_id_unique',
        'webhook_deliveries_endpoint_event_unique',
        'webhook_deliveries_subscription_event_unique',
    ]);
});
