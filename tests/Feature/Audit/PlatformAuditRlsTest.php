<?php

declare(strict_types=1);

use App\Support\Tenancy\SuperAdminContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The PLATFORM audit carve-out at the DATABASE (Increment I7b).
|
| `audits_platform_select` is the narrowed super-admin SELECT policy —
| `current_setting('app.is_superadmin_context', true) = 'true' AND tenant_id IS NULL`. The second conjunct
| is the entire difference between a platform ledger viewer and a cross-tenant surveillance tool, and this
| file is where that claim is asserted rather than promised.
|
| ⚠️ Every query here uses the RAW query builder or drops the ORM scope explicitly. Leaving the
| `BelongsToTenant` scope on would make these assertions pass whether the Postgres policies existed or not
| — the FeedbackRlsTest lesson, and the reason a bypass written without its `TO meridian_superadmin` clause
| would otherwise sail through a green suite.
*/

beforeEach(function (): void {
    TenantContext::flush();
    clearPlatformRows();
    $this->beforeApplicationDestroyed(purgeCommittedPlatformAuditFixtures(...));
});

afterEach(function (): void {
    clearPlatformRows();
});

it('installs a platform SELECT policy on audits narrowed to rows with no tenant', function (): void {
    $policy = DB::selectOne(
        "SELECT policyname, cmd, permissive, roles::text AS roles, qual, with_check
         FROM pg_policies WHERE tablename = 'audits' AND policyname = 'audits_platform_select'"
    );

    expect($policy)->not->toBeNull()
        ->and($policy->cmd)->toBe('SELECT')
        ->and($policy->permissive)->toBe('PERMISSIVE')
        ->and($policy->roles)->toContain('meridian_superadmin')
        ->and($policy->with_check)->toBeNull();

    // ⭐ THE CONJUNCT. No other feature test in this repo asserts a policy's `qual`; this one does because
    // the whole increment turns on those four words. Substrings rather than an exact match: Postgres
    // deparses the expression with its own ::text casts and parenthesisation, which varies by major version.
    expect((string) $policy->qual)
        ->toContain('tenant_id IS NULL')
        ->toContain('is_superadmin_context')
        // AND → OR is the same defect wearing a different hat, and would silently expose every tenant.
        ->not->toContain(' OR ');
});

it('leaves the generic super-admin bypass on audits at INSERT-only', function (): void {
    /** @var list<object{policyname: string, cmd: string}> $policies */
    $policies = DB::select(
        "SELECT policyname, cmd FROM pg_policies WHERE tablename = 'audits' AND policyname LIKE 'audits_superadmin_%'"
    );

    // The tripwire for a future `applySuperAdminBypass('audits', ['SELECT'])` added "for symmetry" — that
    // helper's gate is unrestricted, so it would hand the operator every tenant's complete history.
    expect($policies)->toHaveCount(1)
        ->and($policies[0]->policyname)->toBe('audits_superadmin_insert')
        ->and($policies[0]->cmd)->toBe('INSERT');
});

it('leaves the base tenant SELECT policy on audits completely unchanged', function (): void {
    $policy = DB::selectOne(
        "SELECT qual FROM pg_policies WHERE tablename = 'audits' AND policyname = 'audits_tenant_select'"
    );

    // The "this is not the widening 2026_08_06_000003 forbids" claim, asserted. A tenant connection must
    // see exactly what it saw before I7b ran. (`NULLIF` contains the substring NULL but never `IS NULL`.)
    expect($policy)->not->toBeNull()
        ->and((string) $policy->qual)
        ->toContain('app.current_tenant_id')
        ->toContain('NULLIF')
        ->not->toContain('IS NULL')
        ->not->toContain('is_superadmin_context');
});

it('still has NO update or delete policy on audits, for any role', function (): void {
    /** @var list<object{policyname: string}> $policies */
    $policies = DB::select("SELECT policyname FROM pg_policies WHERE tablename = 'audits' AND cmd IN ('UPDATE','DELETE')");

    // Net-new over AuditAppendOnlyRlsTest, which proves denial BEHAVIOURALLY on the app connection under a
    // tenant context — and would therefore not catch an UPDATE policy scoped `TO meridian_superadmin`.
    expect($policies)->toBe([]);

    $flags = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = 'audits'");
    expect($flags->relrowsecurity)->toBeTrue()->and($flags->relforcerowsecurity)->toBeTrue();
});

it('grants the elevated role SELECT and INSERT on audits and nothing more', function (): void {
    // A privilege-level guard, independent of policies: it catches a widened `GRANT ALL` that the missing
    // UPDATE/DELETE policies would still (correctly) block but which has no business existing.
    $expected = ['SELECT' => true, 'INSERT' => true, 'UPDATE' => false, 'DELETE' => false, 'TRUNCATE' => false];

    foreach ($expected as $privilege => $granted) {
        $actual = DB::selectOne(
            'SELECT has_table_privilege(?, ?, ?) AS ok',
            ['meridian_superadmin', 'audits', $privilege]
        );

        expect($actual->ok)->toBe($granted, "has_table_privilege for {$privilege}");
    }
});

it('lets the elevated role read a NULL-tenant row while the context is open', function (): void {
    $operator = committedSuperAdmin('reader@platformaudittest.local');
    $id = committedAudit(null, $operator->id);

    $count = DB::connection(SuperAdminContext::CONNECTION)->transaction(function () use ($id): int {
        SuperAdminContext::applyLocal();

        // The RAW builder, so no ORM behaviour can be doing the work this asserts the policy does.
        return DB::connection(SuperAdminContext::CONNECTION)->table('audits')->where('id', $id)->count();
    });

    expect($count)->toBe(1);
});

it('never lets the elevated role read a TENANT-scoped audit row, even with the context open', function (): void {
    // ⭐ THE LOAD-BEARING ONE. This single assertion is the entire difference between
    // TenantIsolation::platformRowsBypass() and TenantIsolation::applySuperAdminBypass(). Swap one helper
    // for the other in the migration and only this line goes red.
    $tenant = committedPlatformTenant('platform-audit-foreign');
    $operator = committedSuperAdmin('foreign@platformaudittest.local');
    $id = committedAudit($tenant->id, $operator->id, 'form');

    $count = DB::connection(SuperAdminContext::CONNECTION)->transaction(function () use ($id): int {
        SuperAdminContext::applyLocal();

        return DB::connection(SuperAdminContext::CONNECTION)->table('audits')->where('id', $id)->count();
    });

    expect($count)->toBe(0);
});

it('fails closed for the elevated role when the super-admin context is NOT open', function (): void {
    $operator = committedSuperAdmin('noctx@platformaudittest.local');
    $id = committedAudit(null, $operator->id);

    // Asserted SEPARATELY from the happy path, the PlatformSettingsWriteTest discipline: without this a
    // missing POLICY would pass on the strength of the GRANT alone.
    $count = DB::connection(SuperAdminContext::CONNECTION)->transaction(
        fn (): int => DB::connection(SuperAdminContext::CONNECTION)->table('audits')->where('id', $id)->count()
    );

    expect($count)->toBe(0);
});

it('grants nothing when the super-admin GUC is set on the ordinary application connection', function (): void {
    $tenant = committedPlatformTenant('platform-audit-rolescope');
    $operator = committedSuperAdmin('rolescope@platformaudittest.local');
    $platformRow = committedAudit(null, $operator->id);
    $tenantRow = committedAudit($tenant->id, $operator->id, 'form');

    // The policy is scoped `TO meridian_superadmin`. Setting the gate on the app connection must grant
    // nothing — this is what catches a carve-out written without its TO clause, which would otherwise let
    // ANY connection that can set a GUC read the whole table.
    DB::statement("SELECT set_config('app.is_superadmin_context', 'true', false)");

    try {
        expect(DB::table('audits')->where('id', $platformRow)->count())->toBe(0);
        expect(DB::table('audits')->where('id', $tenantRow)->count())->toBe(0);
    } finally {
        DB::statement("SELECT set_config('app.is_superadmin_context', NULL, false)");
    }
});
