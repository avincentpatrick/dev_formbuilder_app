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
| field_library nullable-global RLS (Increment G9b) — mirrors FormTemplateRlsTest for the second adopter of
| the shape: platform (NULL-tenant) questions are readable by every tenant, but a tenant connection can never
| AUTHOR one; a tenant's own private question is invisible to other tenants. Raw DB:: queries prove the
| DATABASE'S enforcement, not the ORM (the model omits BelongsToTenant on purpose). Platform rows are written
| via the privileged connection, which commits outside RefreshDatabase's transaction, so afterEach cleans them.
*/

beforeEach(function (): void {
    TenantContext::flush();
    DB::connection('pgsql_privileged')->table('field_library')->delete();
});

afterEach(function (): void {
    DB::connection('pgsql_privileged')->table('field_library')->delete();
});

/** @return array<string, mixed> */
function libraryRow(?string $tenantId, string $name): array
{
    return [
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => $tenantId,
        'name' => $name,
        'field_type' => 'short_text',
        'default_label' => $name,
        'default_config' => json_encode([]),
        'default_validations' => json_encode([]),
        'is_active' => true,
        'usage_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

it('has RLS enabled AND forced on field_library', function (): void {
    $meta = DB::selectOne(
        'select relrowsecurity::int as enabled, relforcerowsecurity::int as forced '
        .'from pg_class where relname = ?',
        ['field_library']
    );

    expect((int) $meta->enabled)->toBe(1);
    expect((int) $meta->forced)->toBe(1);
});

it('refuses to author a platform-global (NULL-tenant) question from a tenant context', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    TenantContext::applyLocal($a->id);

    expect(fn () => DB::table('field_library')->insert(libraryRow(null, 'Forged platform')))
        ->toThrow(QueryException::class);
});

it('lets a tenant read platform questions plus its own, but never another tenant\'s', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $b = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo']);

    // A genuine platform row via the only path allowed to write one.
    DB::connection('pgsql_privileged')->table('field_library')->insert(libraryRow(null, 'Platform Age'));

    TenantContext::applyLocal($a->id);
    DB::table('field_library')->insert(libraryRow($a->id, 'Alpha private'));

    TenantContext::applyLocal($b->id);
    DB::table('field_library')->insert(libraryRow($b->id, 'Bravo private'));

    // A sees the platform question + its own, but not Bravo's.
    TenantContext::applyLocal($a->id);
    $names = DB::table('field_library')->pluck('name')->sort()->values()->all();
    expect($names)->toBe(['Alpha private', 'Platform Age']);
});

it('cannot UPDATE a platform (NULL-tenant) question from a tenant context', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $row = libraryRow(null, 'Platform Age');
    DB::connection('pgsql_privileged')->table('field_library')->insert($row);

    TenantContext::applyLocal($a->id);

    // The row is READABLE (widened SELECT) but the strict UPDATE policy matches zero rows — a naive
    // usage_count bump on the app connection silently no-ops (the reason PlatformRowCounter exists).
    expect(DB::table('field_library')->where('id', $row['id'])->update(['usage_count' => 99]))->toBe(0);
    expect(DB::connection('pgsql_privileged')->table('field_library')->where('id', $row['id'])->value('usage_count'))->toBe(0);
});
