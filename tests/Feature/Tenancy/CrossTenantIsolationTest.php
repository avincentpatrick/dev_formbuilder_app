<?php

declare(strict_types=1);

use App\Models\Probe;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Cross-tenant isolation fuzz pack (ADR-0002 §D6) — the Increment-A DoD.
|--------------------------------------------------------------------------
| Runs as the NON-superuser `meridian_app` role, which also OWNS the probe tables. Because the owner
| is subject to RLS only when FORCE is set, every "Tenant A cannot touch Tenant B" assertion below is
| ALSO the proof that FORCE ROW LEVEL SECURITY is active: drop the FORCE line in TenantIsolation and
| the owner would see everything, turning this whole file red. Assertions deliberately use raw DB::
| queries (which bypass the Eloquent BelongsToTenant scope) so what is proven is the DATABASE'S
| enforcement, not the ORM convenience layer.
*/

beforeEach(function (): void {
    // The DB session variable is transaction-scoped (auto-reset each test); the PHP mirror is not.
    TenantContext::flush();
});

afterEach(function (): void {
    // Platform-global rows are seeded via the privileged connection, which is NOT wrapped in
    // RefreshDatabase's transaction — so its writes commit and must be cleaned up by hand.
    DB::connection('pgsql_privileged')->table('global_probes')->delete();
});

/**
 * Seed two tenants, one probe each. Each probe must be inserted under its own tenant's context
 * (the INSERT WITH CHECK policy forbids writing another tenant's row), which itself exercises the
 * write policy while setting up.
 *
 * @return array{0: Tenant, 1: Tenant, 2: string, 3: string}
 */
function seedTwoTenants(): array
{
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $b = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo']);

    TenantContext::applyLocal($a->id);
    $probeA = Probe::create(['note' => 'alpha-secret']);

    TenantContext::applyLocal($b->id);
    $probeB = Probe::create(['note' => 'bravo-secret']);

    return [$a, $b, $probeA->id, $probeB->id];
}

it('runs the fuzz pack against real PostgreSQL as a non-superuser role', function (): void {
    // RLS is silently ignored for a superuser / BYPASSRLS role — if the suite ever connects as one,
    // every isolation assertion below would pass vacuously. Guard against that regression explicitly.
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    // Cast to int in SQL: pdo_pgsql returns booleans as 't'/'f' strings, and (bool) 'f' === true.
    $role = DB::selectOne(
        'select rolsuper::int as is_super, rolbypassrls::int as bypass_rls '
        .'from pg_roles where rolname = current_user'
    );

    expect((int) $role->is_super)->toBe(0);
    expect((int) $role->bypass_rls)->toBe(0);
});

it('has RLS enabled AND forced on the tenant-scoped table', function (): void {
    $meta = DB::selectOne(
        'select relrowsecurity::int as enabled, relforcerowsecurity::int as forced '
        .'from pg_class where relname = ?',
        ['probes']
    );

    expect((int) $meta->enabled)->toBe(1);  // ENABLE
    expect((int) $meta->forced)->toBe(1);   // FORCE — applies to the owning app role
});

it('shows a tenant only its own rows, never another tenant\'s (SELECT)', function (): void {
    [$a, $b] = seedTwoTenants();

    TenantContext::applyLocal($a->id);
    $rows = DB::table('probes')->get(); // raw query — no Eloquent scope, pure RLS

    expect($rows)->toHaveCount(1);
    expect($rows->first()->note)->toBe('alpha-secret');
});

it('fails closed: no tenant context ⇒ zero rows, not all rows', function (): void {
    seedTwoTenants();

    TenantContext::applyLocal(null); // clear context within the transaction

    expect(DB::table('probes')->count())->toBe(0);
});

it('refuses to INSERT a row belonging to another tenant (WITH CHECK)', function (): void {
    [$a, $b] = seedTwoTenants();

    TenantContext::applyLocal($a->id);

    // Raw insert with B's tenant_id while in A's context — the write policy must reject it.
    expect(fn () => DB::table('probes')->insert([
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => $b->id,
        'note' => 'smuggled',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('cannot UPDATE or DELETE another tenant\'s rows', function (): void {
    [$a, $b, , $probeBId] = seedTwoTenants();

    TenantContext::applyLocal($a->id);

    // B's row is invisible under A's context, so both statements match zero rows.
    expect(DB::table('probes')->where('id', $probeBId)->update(['note' => 'hacked']))->toBe(0);
    expect(DB::table('probes')->where('id', $probeBId)->delete())->toBe(0);

    // Confirm from B's side that the row is untouched.
    TenantContext::applyLocal($b->id);
    expect(DB::table('probes')->where('id', $probeBId)->value('note'))->toBe('bravo-secret');
});

it('keeps the Eloquent BelongsToTenant scope in agreement with RLS', function (): void {
    [$a, $b] = seedTwoTenants();

    TenantContext::applyLocal($a->id);

    // Auto-fill: create without specifying tenant_id, it adopts the current context …
    $created = Probe::create(['note' => 'alpha-two']);
    expect($created->tenant_id)->toBe($a->id);

    // … and the scoped model query and the raw (RLS-only) query see the same set.
    expect(Probe::count())->toBe(2);                              // ORM scope
    expect(DB::table('probes')->count())->toBe(2);               // RLS
    expect(Probe::withoutTenantScope()->count())->toBe(2);       // scope dropped, RLS still bites
});

it('refuses to author a platform-global (NULL-tenant) row from a tenant context', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    TenantContext::applyLocal($a->id);

    // The nullable-global SELECT policy is widened, but every WRITE policy stays strict: a
    // tenant-scoped connection can never mint a global row (only the elevated seeder role can).
    expect(fn () => DB::table('global_probes')->insert([
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => null,
        'note' => 'forged-global',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('lets a tenant read platform-global (NULL) rows alongside its own', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);

    // Seed a genuine global row via the privileged (superuser) connection — the only path allowed to
    // write a NULL-tenant row. It commits outside the test transaction; afterEach() cleans it up.
    DB::connection('pgsql_privileged')->table('global_probes')->insert([
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => null,
        'note' => 'platform-global',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    TenantContext::applyLocal($a->id);
    DB::table('global_probes')->insert([
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => $a->id,
        'note' => 'alpha-own',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A sees BOTH its own row and the global row (the widened SELECT policy).
    $notes = DB::table('global_probes')->pluck('note')->sort()->values()->all();
    expect($notes)->toBe(['alpha-own', 'platform-global']);
});
