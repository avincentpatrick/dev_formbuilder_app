<?php

declare(strict_types=1);

use App\Enums\SettingKey;
use App\Models\Setting;
use App\Services\Admin\SuperAdminService;
use App\Services\Settings\PlatformSettings;
use App\Support\Tenancy\SuperAdminContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Platform (NULL-tenant) settings writes (Increment I5, PRD Feature #10).
|
| THREE facts have to hold together, and each fails in a different, quiet way on its own:
|   1. the elevated write PERSISTS (the GRANT is present — table privileges are checked BEFORE RLS, so a
|      missing one dies "permission denied" before any policy is consulted);
|   2. the same write from the APP connection affects ZERO rows and raises NOTHING (the strict UPDATE
|      policy — the trap that makes the elevated path necessary rather than merely tidy);
|   3. the elevated connection WITHOUT the GUC open is refused (the policy is doing the work, not just the
|      grant — assert them separately or a missing policy passes on the strength of the grant alone).
|
| ⚠️ HARNESS SUBTLETY, the SuperAdminBypassTest one: `pgsql_superadmin` is a SEPARATE connection, so it
| neither sees nor is seen by RefreshDatabase's uncommitted transaction on the default connection. The
| platform rows this test writes are therefore COMMITTED and must be cleaned by hand — on the privileged
| connection, which is safe here because the default connection's open transaction never touches
| NULL-tenant rows.
*/

// ⚠️ `clearPlatformRows()` and `committedSuperAdmin()` MOVED TO tests/Pest.php in I7b. Pest loads every
// test file into one process, so re-declaring a file-scope function in a second file is a fatal redeclare
// — and I7b's platform-audit suites need both. They are globals from Pest.php now; nothing to import.

beforeEach(function (): void {
    clearPlatformRows();
    app(PlatformSettings::class)->forget();
});

afterEach(function (): void {
    clearPlatformRows();
});

it('persists a platform setting written through the elevated service', function (): void {
    $actor = committedSuperAdmin('maintenance@platformsettingstest.local');

    app(SuperAdminService::class)->updatePlatformSettings([
        SettingKey::MaintenanceEnabled->value => true,
        SettingKey::MaintenanceMessage->value => 'Back at 03:00 UTC.',
    ], $actor);

    $rows = DB::connection('pgsql_privileged')->table('settings')->whereNull('tenant_id')->pluck('value', 'key');

    expect($rows['maintenance.enabled'])->toBe('true');
    expect(json_decode((string) $rows['maintenance.message'], true))->toBe('Back at 03:00 UTC.');

    // And the read side agrees, on the ordinary app connection — nullable_global's SELECT reveals platform
    // rows with no tenant context at all, which is what lets the global middleware read them anywhere.
    app(PlatformSettings::class)->forget();
    expect(app(PlatformSettings::class)->maintenanceEnabled())->toBeTrue();
    expect(app(PlatformSettings::class)->maintenanceMessage())->toBe('Back at 03:00 UTC.');
});

it('writes a NULL-tenant audit row for the platform change', function (): void {
    $actor = committedSuperAdmin('signup@platformsettingstest.local');

    app(SuperAdminService::class)->updatePlatformSettings([
        SettingKey::RegistrationOpenSignup->value => false,
    ], $actor);

    $audit = DB::connection('pgsql_privileged')->table('audits')
        ->whereNull('tenant_id')->where('auditable_type', 'settings')->first();

    expect($audit)->not->toBeNull();
    // Keyed on the acting operator, spec §1's device for a target with no single addressable uuid — the
    // change is a set of platform-wide keys, not one record.
    expect($audit->auditable_id)->toBe($actor->id);
    expect($audit->user_id)->toBe($actor->id);
    expect(json_decode((string) $audit->new_values, true))->toBe(['registration.open_signup' => false]);
    // Invisible to every tenant by construction: `audits` keeps a STRICT select policy (it is not
    // nullable_global), which is the point — a tenant must not read the operator's platform actions.
    expect(Setting::query()->getConnection()->getName())->toBe('pgsql');
});

it('silently affects ZERO rows when the same write is attempted on the app connection', function (): void {
    // Seed a genuine platform row through the only path allowed to author one.
    DB::connection('pgsql_privileged')->table('settings')->insert([
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => null,
        'key' => 'maintenance.enabled',
        'value' => json_encode(false),
        'updated_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // No exception, no warning, no rows. THIS is why PlatformSettings is read-only and the write lives on
    // SuperAdminService — a naive `Setting::where(...)->update(...)` here would look like it worked.
    $affected = DB::table('settings')->where('key', 'maintenance.enabled')->update(['value' => json_encode(true)]);

    expect($affected)->toBe(0);
    expect(DB::connection('pgsql_privileged')->table('settings')->where('key', 'maintenance.enabled')->value('value'))
        ->toBe('false');
});

it('refuses the elevated connection when the super-admin context is NOT open', function (): void {
    // The grant alone is not the control. Without SuperAdminContext::applyLocal() the bypass policy's gate
    // (`app.is_superadmin_context = 'true'`) is NULL, so the strict base policies decide — and they refuse
    // a NULL-tenant INSERT. Asserted separately from the happy path precisely so a missing POLICY cannot
    // pass on the strength of the GRANT.
    DB::connection(SuperAdminContext::CONNECTION)->transaction(function (): void {
        expect(fn () => DB::connection(SuperAdminContext::CONNECTION)->table('settings')->insert([
            'id' => Uuid::uuid7()->toString(),
            'tenant_id' => null,
            'key' => 'registration.open_signup',
            'value' => json_encode(false),
            'updated_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});
