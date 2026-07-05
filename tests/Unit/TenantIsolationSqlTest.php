<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantIsolation;

// Pure SQL-generation tests (no database): they pin the RLS policy text so a careless edit to the
// helper — dropping FORCE, widening a write policy, losing the fail-closed missing_ok form — is
// caught here in the fast unit suite, before the Postgres-backed fuzz pack even runs.

function joined(array $statements): string
{
    return implode("\n", $statements);
}

it('emits ENABLE and FORCE row level security for the strict shape', function (): void {
    $sql = joined(TenantIsolation::strictSql('probes'));

    expect($sql)
        ->toContain('ALTER TABLE probes ENABLE ROW LEVEL SECURITY;')
        ->toContain('ALTER TABLE probes FORCE ROW LEVEL SECURITY;');
});

it('uses the fail-closed NULLIF + two-argument current_setting form', function (): void {
    // The `, true` (missing_ok) argument makes an unset context yield NULL, and NULLIF(...,'') makes
    // a set-then-cleared context ('' — how a custom GUC reads back after reset) also become NULL.
    // Both must map to "no rows", never an error or "all rows". Dropping either breaks fail-closed.
    $sql = joined(TenantIsolation::strictSql('probes'));

    expect($sql)->toContain("NULLIF(current_setting('app.current_tenant_id', true), '')::uuid");
});

it('creates one policy per command, with WITH CHECK on writes', function (): void {
    $sql = joined(TenantIsolation::strictSql('probes'));

    expect($sql)
        ->toContain('CREATE POLICY probes_tenant_select ON probes FOR SELECT USING')
        ->toContain('CREATE POLICY probes_tenant_insert ON probes FOR INSERT WITH CHECK')
        ->toContain('CREATE POLICY probes_tenant_update ON probes FOR UPDATE USING')
        ->toContain('CREATE POLICY probes_tenant_delete ON probes FOR DELETE USING');

    // UPDATE must carry both USING (which existing rows are visible) and WITH CHECK (what the new
    // row may become) — omitting the CHECK would let a row be updated OUT of the tenant.
    $update = collect(TenantIsolation::strictSql('probes'))
        ->first(fn (string $s): bool => str_contains($s, 'FOR UPDATE'));
    expect($update)->toContain('USING')->toContain('WITH CHECK');
});

it('widens only SELECT for the nullable-global shape, keeping writes strict', function (): void {
    $statements = TenantIsolation::nullableGlobalSql('global_probes');

    $select = collect($statements)->first(fn (string $s): bool => str_contains($s, 'FOR SELECT'));
    $insert = collect($statements)->first(fn (string $s): bool => str_contains($s, 'FOR INSERT'));

    // SELECT reveals platform-global (NULL) rows …
    expect($select)->toContain('OR tenant_id IS NULL');
    // … but a tenant connection can never author one.
    expect($insert)->not->toContain('IS NULL');
});

it('keys the belongs-to-user shape on the user setting, not the tenant setting', function (): void {
    $sql = joined(TenantIsolation::belongsToUserSql('user_ui_preferences'));

    expect($sql)
        ->toContain("NULLIF(current_setting('app.current_user_id', true), '')::uuid")
        ->not->toContain('app.current_tenant_id');
});

it('builds the users visibility shape as a SELECT-only membership join', function (): void {
    $statements = TenantIsolation::usersVisibilitySql('users');
    $sql = joined($statements);

    expect($sql)
        ->toContain('FORCE ROW LEVEL SECURITY')
        ->toContain('FROM tenant_users tu')
        ->toContain("tu.status = 'active'");

    // Writes on a user's own account are app-layer authorized (RBAC doc §6), so no write policy here.
    expect(collect($statements)->filter(fn (string $s): bool => str_contains($s, 'FOR INSERT')))->toBeEmpty();
});

it('guards published rows from update and delete in the draft-guard shape', function (): void {
    $statements = TenantIsolation::draftGuardSql('form_versions');

    $update = collect($statements)->first(fn (string $s): bool => str_contains($s, 'FOR UPDATE'));
    $delete = collect($statements)->first(fn (string $s): bool => str_contains($s, 'FOR DELETE'));

    expect($update)->toContain("status <> 'published'");
    expect($delete)->toContain("status <> 'published'");
});

it('makes users write policies permissive, with a pre-auth SELECT carve-out for the auth role', function (): void {
    $statements = TenantIsolation::usersWritePoliciesSql('users', 'meridian_auth');
    $sql = joined($statements);

    // Writes are app-authorized (RBAC §6): permissive, not tenant-scoped.
    expect($sql)
        ->toContain('CREATE POLICY users_app_insert ON users FOR INSERT WITH CHECK (true)')
        ->toContain('CREATE POLICY users_app_update ON users FOR UPDATE USING (true) WITH CHECK (true)')
        ->toContain('CREATE POLICY users_app_delete ON users FOR DELETE USING (true)');

    // The pre-auth role gets a permissive SELECT scoped TO it only (writes are covered by the above).
    expect($sql)->toContain('CREATE POLICY users_auth_select ON users FOR SELECT TO meridian_auth USING (true)');
    expect(collect($statements)->filter(
        fn (string $s): bool => str_contains($s, 'TO meridian_auth') && ! str_contains($s, 'FOR SELECT')
    ))->toBeEmpty();
});

it('rejects an unsafe auth role name in users write policies', function (): void {
    expect(fn () => TenantIsolation::usersWritePoliciesSql('users', 'evil; DROP ROLE x'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects unsafe identifiers rather than interpolating them into DDL', function (): void {
    expect(fn () => TenantIsolation::strictSql('probes; DROP TABLE tenants;--'))
        ->toThrow(InvalidArgumentException::class);
});
