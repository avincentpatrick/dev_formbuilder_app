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

it('uses the fail-closed two-argument current_setting form', function (): void {
    // The `, true` (missing_ok) argument is what makes an unset context yield NULL → zero rows,
    // instead of raising an error. Its removal would break the entire fail-closed guarantee.
    $sql = joined(TenantIsolation::strictSql('probes'));

    expect($sql)->toContain("current_setting('app.current_tenant_id', true)::uuid");
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
        ->toContain("current_setting('app.current_user_id', true)::uuid")
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

it('rejects unsafe identifiers rather than interpolating them into DDL', function (): void {
    expect(fn () => TenantIsolation::strictSql('probes; DROP TABLE tenants;--'))
        ->toThrow(InvalidArgumentException::class);
});
