<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| settings nullable-global RLS (Increment I5) — the THIRD adopter of the shape, mirroring
| FieldLibraryRlsTest and FormTemplateRlsTest: platform (NULL-tenant) rows are readable by every tenant,
| a tenant connection can never AUTHOR one, and a tenant's own rows are invisible to other tenants.
|
| Raw DB:: queries throughout, deliberately — this proves the DATABASE'S enforcement, not the ORM's (the
| Setting model omits BelongsToTenant on purpose, precisely so it does not hide the platform rows).
|
| Platform rows are written on the privileged connection, which commits OUTSIDE RefreshDatabase's
| transaction, so both hooks clean them.
|
| The last two tests are the ones this file adds beyond its two siblings, and they exist because §20's
| uniqueness rule is the easiest thing in the increment to get subtly wrong: a plain UNIQUE(tenant_id, key)
| looks equivalent and silently permits two platform rows for one key, because Postgres treats NULL as
| distinct from NULL.
*/

beforeEach(function (): void {
    TenantContext::flush();
    DB::connection('pgsql_privileged')->table('settings')->delete();
});

afterEach(function (): void {
    DB::connection('pgsql_privileged')->table('settings')->delete();
});

/** @return array<string, mixed> */
function settingRow(?string $tenantId, string $key, mixed $value): array
{
    return [
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => $tenantId,
        'key' => $key,
        'value' => json_encode($value),
        'updated_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

it('has RLS enabled AND forced on settings', function (): void {
    $meta = DB::selectOne(
        'select relrowsecurity::int as enabled, relforcerowsecurity::int as forced '
        .'from pg_class where relname = ?',
        ['settings']
    );

    expect((int) $meta->enabled)->toBe(1);
    expect((int) $meta->forced)->toBe(1);
});

it('refuses to author a platform (NULL-tenant) setting from a tenant context', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    TenantContext::applyLocal($a->id);

    expect(fn () => DB::table('settings')->insert(settingRow(null, 'maintenance.enabled', true)))
        ->toThrow(QueryException::class);
});

it('lets a tenant read platform settings plus its own, but never another tenant\'s', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $b = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo']);

    DB::connection('pgsql_privileged')->table('settings')
        ->insert(settingRow(null, 'registration.open_signup', true));

    TenantContext::applyLocal($a->id);
    DB::table('settings')->insert(settingRow($a->id, 'registration.invite_only', false));

    TenantContext::applyLocal($b->id);
    DB::table('settings')->insert(settingRow($b->id, 'modules.webhooks', false));

    TenantContext::applyLocal($a->id);
    $keys = DB::table('settings')->pluck('key')->sort()->values()->all();
    expect($keys)->toBe(['registration.invite_only', 'registration.open_signup']);
});

it('cannot UPDATE a platform (NULL-tenant) setting from a tenant context — and fails SILENTLY', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $row = settingRow(null, 'maintenance.enabled', false);
    DB::connection('pgsql_privileged')->table('settings')->insert($row);

    TenantContext::applyLocal($a->id);

    // READABLE (the widened SELECT) but the strict UPDATE policy matches zero rows. No exception, no
    // warning — which is exactly why every platform write goes through SuperAdminService::elevated()
    // rather than through an ordinary query that would look like it worked.
    expect(DB::table('settings')->where('id', $row['id'])->update(['value' => json_encode(true)]))->toBe(0);
    expect(DB::connection('pgsql_privileged')->table('settings')->where('id', $row['id'])->value('value'))
        ->toBe('false');
});

it('enforces ONE PLATFORM ROW PER KEY — the half a plain UNIQUE(tenant_id, key) would not', function (): void {
    // The whole reason §20 specifies two PARTIAL unique indexes. Under a plain composite constraint both
    // of these inserts succeed, because NULL is distinct from NULL for uniqueness purposes, and the store
    // silently holds two answers for one question.
    DB::connection('pgsql_privileged')->table('settings')
        ->insert(settingRow(null, 'maintenance.enabled', true));

    expect(fn () => DB::connection('pgsql_privileged')->table('settings')
        ->insert(settingRow(null, 'maintenance.enabled', false)))
        ->toThrow(QueryException::class);
});

it('enforces one row per key per tenant', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);

    TenantContext::applyLocal($a->id);
    DB::table('settings')->insert(settingRow($a->id, 'registration.invite_only', false));

    expect(fn () => DB::table('settings')->insert(settingRow($a->id, 'registration.invite_only', true)))
        ->toThrow(QueryException::class);
});

// A SEPARATE test, not a third assertion on the one above, and the reason is Postgres rather than style:
// a constraint violation aborts the surrounding transaction, and RefreshDatabase wraps the whole test in
// one — so every statement after an expected 23505 dies with 25P02 "current transaction is aborted".
it('still lets two different tenants hold the same key', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $b = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo']);

    TenantContext::applyLocal($a->id);
    DB::table('settings')->insert(settingRow($a->id, 'registration.invite_only', false));

    TenantContext::applyLocal($b->id);
    DB::table('settings')->insert(settingRow($b->id, 'registration.invite_only', true));

    expect(DB::table('settings')->count())->toBe(1); // RLS: Bravo sees only its own
});
