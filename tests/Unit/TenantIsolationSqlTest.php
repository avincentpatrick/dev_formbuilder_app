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

// Increment D — the two published-immutability shapes (form-versioning-schema-migration.md §2).
// form_versions keeps UPDATE strict (publish must flip published→superseded) but DELETE draft-only
// (a published version is undeletable, closing the FK-CASCADE bypass to the immutable children).

it('keeps form_versions UPDATE strict but DELETE draft-only in the form-version shape', function (): void {
    $statements = TenantIsolation::formVersionGuardSql('form_versions');

    $update = collect($statements)->first(fn (string $s): bool => str_contains($s, 'FOR UPDATE'));
    $delete = collect($statements)->first(fn (string $s): bool => str_contains($s, 'FOR DELETE'));

    // UPDATE must NOT constrain on status — the publish transaction has to move a version
    // draft→published and the prior version published→superseded, both via UPDATE.
    expect($update)->not->toContain('status');
    // DELETE only ever matches a draft, so a published/superseded version can never be deleted.
    expect($delete)->toContain("status = 'draft'");
});

it('emits exactly one write policy per command for the form-version shape', function (): void {
    $statements = TenantIsolation::formVersionGuardSql('form_versions');

    foreach (['FOR INSERT', 'FOR UPDATE', 'FOR DELETE'] as $command) {
        expect(collect($statements)->filter(fn (string $s): bool => str_contains($s, $command)))->toHaveCount(1);
    }
});

it('gates child writes on an EXISTS draft parent but never gates SELECT in the draft-child shape', function (): void {
    $statements = TenantIsolation::draftChildGuardSql('form_fields');
    $sql = joined($statements);

    $select = collect($statements)->first(fn (string $s): bool => str_contains($s, 'FOR SELECT'));
    $insert = collect($statements)->first(fn (string $s): bool => str_contains($s, 'FOR INSERT'));

    // SELECT is plain strict tenant isolation — reading published/superseded content must always work.
    expect($select)->not->toContain('EXISTS');
    // Every write is gated on the owning form_versions row still being a draft.
    expect($sql)->toContain(
        "EXISTS (SELECT 1 FROM form_versions fv WHERE fv.id = form_fields.form_version_id AND fv.status = 'draft')"
    );
    expect($insert)->toContain('EXISTS');
});

it('emits exactly one write policy per command for the draft-child shape', function (): void {
    $statements = TenantIsolation::draftChildGuardSql('form_sections');

    foreach (['FOR INSERT', 'FOR UPDATE', 'FOR DELETE'] as $command) {
        expect(collect($statements)->filter(fn (string $s): bool => str_contains($s, $command)))->toHaveCount(1);
    }
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

// Increment B2a — pin the shape each Spatie permission table receives. The migration reuses the
// existing strict / nullable-global generators (no new shape), so these guard that the RIGHT shape is
// wired to the RIGHT table: the assignment pivot is strict, the catalog is nullable-global.

it('applies the strict shape to the model_has_roles assignment pivot', function (): void {
    $sql = joined(TenantIsolation::strictSql('model_has_roles'));

    expect($sql)
        ->toContain('ALTER TABLE model_has_roles FORCE ROW LEVEL SECURITY;')
        ->toContain('CREATE POLICY model_has_roles_tenant_insert ON model_has_roles FOR INSERT WITH CHECK')
        ->toContain("NULLIF(current_setting('app.current_tenant_id', true), '')::uuid");
});

it('applies the nullable-global shape to the roles catalog: readable by all, writable by none', function (): void {
    $statements = TenantIsolation::nullableGlobalSql('roles');
    $select = collect($statements)->first(fn (string $s): bool => str_contains($s, 'FOR SELECT'));
    $insert = collect($statements)->first(fn (string $s): bool => str_contains($s, 'FOR INSERT'));

    expect($select)->toContain('OR tenant_id IS NULL'); // every tenant may read the global catalog …
    expect($insert)->not->toContain('IS NULL');          // … but none may author a global role
});

// Increment B2c — the super-admin cross-tenant carve-out. An ADDITIVE, role-scoped, GUC-gated
// permissive policy layered on a table that already has its base RLS+FORCE.

it('emits a role-scoped, context-gated SELECT bypass without re-enabling FORCE', function (): void {
    $statements = TenantIsolation::superAdminBypassSql('users', 'meridian_superadmin', ['SELECT']);
    $sql = joined($statements);

    expect($sql)->toContain(
        'CREATE POLICY users_superadmin_select ON users FOR SELECT TO meridian_superadmin '
        ."USING (current_setting('app.is_superadmin_context', true) = 'true');"
    );

    // It is a carve-out layered onto an already-secured table — it must NOT re-declare ENABLE/FORCE,
    // and it must NOT touch the tenant/user uuid settings (its gate is a plain text equality).
    expect($sql)
        ->not->toContain('FORCE ROW LEVEL SECURITY')
        ->not->toContain('app.current_tenant_id')
        ->not->toContain('::uuid');
});

it('emits WITH CHECK on the write commands of the bypass shape', function (): void {
    $statements = TenantIsolation::superAdminBypassSql('audits', 'meridian_superadmin', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
    $sql = joined($statements);

    expect($sql)
        ->toContain('CREATE POLICY audits_superadmin_insert ON audits FOR INSERT TO meridian_superadmin WITH CHECK')
        ->toContain('CREATE POLICY audits_superadmin_update ON audits FOR UPDATE TO meridian_superadmin USING')
        ->toContain('CREATE POLICY audits_superadmin_delete ON audits FOR DELETE TO meridian_superadmin USING');
});

it('rejects an unsafe role name or table in the bypass shape', function (): void {
    expect(fn () => TenantIsolation::superAdminBypassSql('users', 'evil; DROP ROLE x'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => TenantIsolation::superAdminBypassSql('users; DROP TABLE tenants;--', 'meridian_superadmin'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an unsupported command in the bypass shape', function (): void {
    expect(fn () => TenantIsolation::superAdminBypassSql('users', 'meridian_superadmin', ['TRUNCATE']))
        ->toThrow(InvalidArgumentException::class);
});

// Increment G10a — the polymorphic-grant guard for `resource_grants`. A morph carries no database
// foreign key, so the plain strict shape would ACCEPT a grant pointing at another tenant's form. Since
// that row is an authorization decision input, these assertions are load-bearing security tests, not
// formatting checks.

/** The alias => table map the real migration passes, mirrored here so the tests stay independent of it. */
function grantTargets(): array
{
    return ['form' => 'forms', 'scope_node' => 'scope_nodes'];
}

it('emits ENABLE and FORCE for the resource-grant guard shape', function (): void {
    $sql = joined(TenantIsolation::resourceGrantGuardSql('resource_grants', grantTargets()));

    expect($sql)
        ->toContain('ALTER TABLE resource_grants ENABLE ROW LEVEL SECURITY;')
        ->toContain('ALTER TABLE resource_grants FORCE ROW LEVEL SECURITY;');
});

it('guards every write with one same-tenant EXISTS branch per registered morph alias', function (): void {
    $sql = joined(TenantIsolation::resourceGrantGuardSql('resource_grants', grantTargets()));

    foreach (['forms', 'scope_nodes'] as $target) {
        // Both halves matter: t.id anchors the target row, t.tenant_id proves it is OURS. Dropping the
        // second is exactly the cross-tenant grant this shape exists to prevent.
        expect($sql)->toContain(
            "EXISTS (SELECT 1 FROM {$target} t WHERE t.id = resource_grants.scopeable_id "
            .'AND t.tenant_id = resource_grants.tenant_id)'
        );
    }

    expect($sql)
        ->toContain("(scopeable_type = 'form' AND EXISTS")
        ->toContain("(scopeable_type = 'scope_node' AND EXISTS");
});

it('applies the target guard to INSERT and UPDATE but never to SELECT or DELETE', function (): void {
    $statements = TenantIsolation::resourceGrantGuardSql('resource_grants', grantTargets());

    $find = function (string $command) use ($statements): string {
        foreach ($statements as $statement) {
            if (str_contains($statement, "FOR {$command} ")) {
                return $statement;
            }
        }

        return '';
    };

    // Writes are guarded …
    expect($find('INSERT'))->toContain('EXISTS');
    expect($find('UPDATE'))->toContain('EXISTS');

    // … reads and revocations are NOT: an orphaned grant (its target hard-deleted) must stay readable,
    // and therefore deletable. Guarding these would strand un-revokable rows.
    expect($find('SELECT'))->not->toContain('EXISTS');
    expect($find('DELETE'))->not->toContain('EXISTS');
});

it('emits exactly ONE policy per command so nothing OR-widens the write guard', function (): void {
    $statements = TenantIsolation::resourceGrantGuardSql('resource_grants', grantTargets());

    // Postgres OR-combines same-command PERMISSIVE policies. A second INSERT policy — e.g. from also
    // calling withTenantIsolation() on this table — would silently restore bare tenant equality and
    // still pass every other test here. This is the assertion that catches that.
    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $command) {
        $matches = array_filter($statements, fn (string $s): bool => str_contains($s, "FOR {$command} "));
        expect($matches)->toHaveCount(1, "expected exactly one {$command} policy");
    }
});

it('keeps the UPDATE USING clause tenant-only while its WITH CHECK is guarded', function (): void {
    $statements = TenantIsolation::resourceGrantGuardSql('resource_grants', grantTargets());
    $update = '';

    foreach ($statements as $statement) {
        if (str_contains($statement, 'FOR UPDATE ')) {
            $update = $statement;
        }
    }

    [$using, $check] = explode('WITH CHECK', $update, 2);

    // USING selects which rows may be updated (tenant-only, so a row whose target was deleted is still
    // editable); WITH CHECK validates the RESULT, which is where the target must be proven same-tenant.
    expect($using)->not->toContain('EXISTS');
    expect($check)->toContain('EXISTS');
});

it('rejects an unsafe table, alias, or target table in the guard shape', function (): void {
    expect(fn () => TenantIsolation::resourceGrantGuardSql('resource_grants; DROP TABLE tenants;--', grantTargets()))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => TenantIsolation::resourceGrantGuardSql('resource_grants', ["evil' OR 1=1--" => 'forms']))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => TenantIsolation::resourceGrantGuardSql('resource_grants', ['form' => 'forms; DROP TABLE x']))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to emit a guard with no morph targets', function (): void {
    // An empty branch list would produce `tenant_id = ctx AND ()` — a syntax error at best, and at worst
    // a shape someone "fixes" by dropping the parentheses, silently removing the guard entirely.
    expect(fn () => TenantIsolation::resourceGrantGuardSql('resource_grants', []))
        ->toThrow(InvalidArgumentException::class);
});
