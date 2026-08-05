<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The guard binds the SUPERUSER too (Increment H25, ADR-0013, Risk R5).
|--------------------------------------------------------------------------
| This pack proves the one property that justifies reaching for a trigger at all rather than adding yet
| another policy: a superuser BYPASSES Row-Level Security, and does NOT bypass a trigger. Without it the
| guard would be exactly as strong as the RLS it sits beside, and ADR-0013 would have no argument.
|
| It needs its own file because of a trap that would make it pass VACUOUSLY. `pgsql_privileged` is a
| separate PDO connection, i.e. a separate session, and RefreshDatabase wraps only the DEFAULT one — so a
| row created the ordinary way is INVISIBLE here, the UPDATE would match zero rows, nothing would throw,
| and the test would report success while proving nothing. The fixture is therefore built ON the
| privileged connection (committed, outside any transaction) and cleaned by hand, the same shape
| PlatformFieldLibrarySeedTest documents.
*/

/** @var array<string, string> */
$ids = [];

beforeEach(function () use (&$ids): void {
    TenantContext::flush();

    $ids = [
        'user' => Uuid::uuid7()->toString(),
        'tenant' => Uuid::uuid7()->toString(),
        'form' => Uuid::uuid7()->toString(),
        'version' => Uuid::uuid7()->toString(),
    ];

    $db = DB::connection('pgsql_privileged');
    $db->table('users')->insert([
        'id' => $ids['user'], 'name' => 'H25 Guard', 'email' => 'h25-guard@meridian.test',
        'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $db->table('tenants')->insert([
        'id' => $ids['tenant'], 'name' => 'H25 Guard', 'slug' => 'h25-guard',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $db->table('forms')->insert([
        'id' => $ids['form'], 'tenant_id' => $ids['tenant'], 'title' => 'Guarded', 'default_locale' => 'en',
        'owner_user_id' => $ids['user'], 'created_by' => $ids['user'],
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $db->table('form_versions')->insert([
        'id' => $ids['version'], 'tenant_id' => $ids['tenant'], 'form_id' => $ids['form'],
        'version_number' => 1, 'status' => 'published', 'title' => 'Guarded',
        'schema_snapshot' => '{}', 'checksum' => str_repeat('a', 64), 'published_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

afterEach(function () use (&$ids): void {
    $db = DB::connection('pgsql_privileged');
    // Tenant first: it cascades forms and their versions away, which incidentally exercises the scope
    // decision (H25 adds NO delete trigger, so this cascade must still run clean). The user cannot go
    // first — forms.owner_user_id is ON DELETE NO ACTION.
    $db->table('tenants')->where('id', $ids['tenant'] ?? '')->delete();
    $db->table('users')->where('id', $ids['user'] ?? '')->delete();
});

it('confirms the privileged connection really is a superuser', function (): void {
    // Anti-vacuity for the test below: if this role were ever downgraded, "the trigger still fired" would
    // prove nothing beyond the ordinary app-role case that the sibling pack already covers.
    $row = DB::connection('pgsql_privileged')
        ->selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');

    expect((bool) $row->rolsuper)->toBeTrue();
});

it('binds the superuser connection: it can reach the row, and still cannot resurrect it', function () use (&$ids): void {
    $db = DB::connection('pgsql_privileged');

    // Anti-vacuity: the row is visible AND updatable from this session, so a refusal below is the guard
    // talking rather than a WHERE clause that matched nothing.
    expect($db->table('form_versions')->where('id', $ids['version'])->update(['updated_at' => now()]))->toBe(1);

    try {
        $db->table('form_versions')->where('id', $ids['version'])->update(['status' => 'draft']);
        $this->fail('The trigger did not fire on the superuser connection.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23001');
        expect($e->getMessage())->toContain('status may only move published to superseded');
    }

    // The privileged connection runs outside any wrapping transaction, so the failed statement leaves the
    // session usable — afterEach cleanup still runs, and the row is provably untouched.
    expect($db->table('form_versions')->where('id', $ids['version'])->value('status'))->toBe('published');
});

it('binds the superuser connection against a frozen-column rewrite too', function () use (&$ids): void {
    $db = DB::connection('pgsql_privileged');

    expect($db->table('form_versions')->where('id', $ids['version'])->update(['updated_at' => now()]))->toBe(1);

    try {
        $db->table('form_versions')->where('id', $ids['version'])->update(['schema_snapshot' => '{"a":1}']);
        $this->fail('The trigger did not refuse a superuser snapshot rewrite.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23001');
        expect($e->getMessage())->toContain('schema_snapshot');
    }

    expect($db->table('form_versions')->where('id', $ids['version'])->value('schema_snapshot'))->toBe('{}');
});
