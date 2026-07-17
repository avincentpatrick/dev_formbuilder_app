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
| form_templates nullable-global RLS (Increment G9a) — mirrors CrossTenantIsolationTest for the real
| adopter of the shape GlobalProbe prototyped: platform (NULL-tenant) templates are readable by every
| tenant, but a tenant connection can never AUTHOR one; a tenant's own private template is invisible to
| other tenants. Raw DB:: queries prove the DATABASE'S enforcement, not the ORM (the model omits
| BelongsToTenant on purpose). Platform rows are written via the privileged connection, which commits
| outside RefreshDatabase's transaction, so afterEach cleans them by hand.
*/

beforeEach(function (): void {
    TenantContext::flush();
    DB::connection('pgsql_privileged')->table('form_templates')->delete();
});

afterEach(function (): void {
    DB::connection('pgsql_privileged')->table('form_templates')->delete();
});

/** @return array<string, mixed> */
function templateRow(?string $tenantId, string $name): array
{
    return [
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => $tenantId,
        'name' => $name,
        'schema_blueprint' => json_encode(['sections' => [], 'fields' => []]),
        'is_public' => $tenantId === null,
        'usage_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

it('has RLS enabled AND forced on form_templates', function (): void {
    $meta = DB::selectOne(
        'select relrowsecurity::int as enabled, relforcerowsecurity::int as forced '
        .'from pg_class where relname = ?',
        ['form_templates']
    );

    expect((int) $meta->enabled)->toBe(1);
    expect((int) $meta->forced)->toBe(1);
});

it('refuses to author a platform-global (NULL-tenant) template from a tenant context', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    TenantContext::applyLocal($a->id);

    expect(fn () => DB::table('form_templates')->insert(templateRow(null, 'Forged platform')))
        ->toThrow(QueryException::class);
});

it('lets a tenant read platform templates plus its own, but never another tenant\'s', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $b = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo']);

    // A genuine platform row via the only path allowed to write one.
    DB::connection('pgsql_privileged')->table('form_templates')->insert(templateRow(null, 'Platform Gallery'));

    TenantContext::applyLocal($a->id);
    DB::table('form_templates')->insert(templateRow($a->id, 'Alpha private'));

    TenantContext::applyLocal($b->id);
    DB::table('form_templates')->insert(templateRow($b->id, 'Bravo private'));

    // A sees the platform template + its own, but not Bravo's.
    TenantContext::applyLocal($a->id);
    $names = DB::table('form_templates')->pluck('name')->sort()->values()->all();
    expect($names)->toBe(['Alpha private', 'Platform Gallery']);
});

it('cannot UPDATE a platform (NULL-tenant) template from a tenant context', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $row = templateRow(null, 'Platform Gallery');
    DB::connection('pgsql_privileged')->table('form_templates')->insert($row);

    TenantContext::applyLocal($a->id);

    // The row is READABLE (widened SELECT) but the strict UPDATE policy matches zero rows — a naive
    // usage_count bump on the app connection silently no-ops (the reason PlatformRowCounter exists).
    expect(DB::table('form_templates')->where('id', $row['id'])->update(['usage_count' => 99]))->toBe(0);
    expect(DB::connection('pgsql_privileged')->table('form_templates')->where('id', $row['id'])->value('usage_count'))->toBe(0);
});
