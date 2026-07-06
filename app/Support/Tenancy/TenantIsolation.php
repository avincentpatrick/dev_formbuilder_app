<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Row-Level Security policy generator for tenant-scoped tables (ADR-0002 §D2).
 *
 * Every tenant-scoped table's migration ends with one call here rather than hand-writing
 * policies, so no single migration author can forget the backstop. The class deliberately
 * separates SQL *generation* (the pure `*Sql()` methods — unit-testable with no database)
 * from *execution* (the `apply*()` methods that run inside a migration).
 *
 * Several policy shapes exist across the schema; this class emits all of them (ADR-0002 §D2,
 * multi-tenancy-rbac-design.md §6, form-versioning-schema-migration.md §2). strict + nullable-global
 * are exercised in Increment A; the users/belongs-to-user shapes land in Increment B; and Increment D
 * adds the two versioning shapes — `formVersionGuard` (form_versions: strict writes, draft-only DELETE)
 * and `draftChildGuard` (form_sections/form_fields/form_field_validations: writes only against a draft
 * version). Each is emitted + unit-tested here and fuzz-tested when its real table lands.
 *
 * Non-negotiable invariants, encoded once here:
 *   - FORCE ROW LEVEL SECURITY: without it a table's owner (our migration/app role) bypasses
 *     RLS entirely, silently defeating the backstop.
 *   - current_setting('app.current_tenant_id', true): the two-argument (missing_ok = true)
 *     form. A connection with no tenant context set yields NULL, and `tenant_id = NULL`
 *     matches ZERO rows — the fail-closed default ("no context ⇒ no rows", never "all rows").
 */
final class TenantIsolation
{
    private const TENANT_SETTING = 'app.current_tenant_id';

    private const USER_SETTING = 'app.current_user_id';

    private const SUPERADMIN_SETTING = 'app.is_superadmin_context';

    /**
     * Strict tenant equality — the default shape for virtually every tenant-scoped table.
     */
    public static function strict(string $table, string $tenantColumn = 'tenant_id'): void
    {
        self::execute(self::strictSql($table, $tenantColumn));
    }

    /**
     * Nullable-global shape (ADR-0002 §D2 named exception): rows with a NULL discriminator are
     * platform-provided and visible to every tenant. Only SELECT is widened; writes stay strict
     * so a tenant-scoped connection can never author a global row (only the elevated seeder role
     * can). Adopters so far: field_library, form_templates, settings, roles, permissions.
     */
    public static function nullableGlobal(string $table, string $tenantColumn = 'tenant_id'): void
    {
        self::execute(self::nullableGlobalSql($table, $tenantColumn));
    }

    /**
     * "Belongs to me" shape — a row scoped to a person, not an organization (user_ui_preferences).
     * Keyed on app.current_user_id rather than app.current_tenant_id.
     */
    public static function belongsToUser(string $table, string $userColumn = 'user_id'): void
    {
        self::execute(self::belongsToUserSql($table, $userColumn));
    }

    /**
     * The `users` fourth shape (RBAC §6): SELECT-only, visible to self + active co-tenant members.
     * Pair with usersWritePolicies() — this generator emits no write policies, and FORCE RLS denies
     * all writes without one.
     */
    public static function usersVisibility(string $table = 'users'): void
    {
        self::execute(self::usersVisibilitySql($table));
    }

    /**
     * Write policies for `users`, and the pre-auth carve-out for the auth role. INSERT is permissive
     * (registration/invite-placeholder run with no user context — app-layer authorizes); UPDATE/DELETE
     * are own-row (a user modifies only their own account, while authenticated). The `pgsql_auth` role
     * (RlsAwareUserProvider) additionally gets permissive SELECT/UPDATE so login/password-reset can
     * resolve and write a user with no context — scoped `TO` that role only, never widening the app role.
     */
    public static function usersWritePolicies(string $table = 'users'): void
    {
        $authRole = config('database.connections.pgsql_auth.username');
        self::execute(self::usersWritePoliciesSql($table, is_string($authRole) ? $authRole : null));
    }

    /**
     * Super-admin cross-tenant carve-out (ADR-0002 §D3, RBAC §9). An ADDITIVE permissive policy layered
     * on a table that already has its base RLS shape + FORCE — scoped `TO` the elevated
     * `meridian_superadmin` role and gated on the `app.is_superadmin_context` GUC the narrow
     * SuperAdminService sets transaction-locally. Because RLS ORs permissive policies, this widens
     * visibility to "all rows" only when that GUC is 'true' AND the connection is the elevated role;
     * every ordinary connection is unaffected. Defaults to SELECT-only (the only cross-tenant need in
     * B2c); pass more commands as future platform tables require them. The role is read from the
     * pgsql_superadmin connection config, mirroring usersWritePolicies()'s auth-role lookup.
     *
     * @param  list<string>  $commands
     */
    public static function applySuperAdminBypass(string $table, array $commands = ['SELECT']): void
    {
        $role = config('database.connections.pgsql_superadmin.username');
        self::execute(self::superAdminBypassSql($table, is_string($role) ? $role : 'meridian_superadmin', $commands));
    }

    /**
     * The `form_versions` shape (Increment D, form-versioning-schema-migration.md §2): strict tenant
     * isolation for SELECT/INSERT/UPDATE — UPDATE stays strict so the publish transaction can perform
     * every legitimate status transition (draft→published, published→superseded) — PLUS a DELETE
     * carve-out that permits deleting only a `draft` version. Blocking DELETE of a published/superseded
     * version is what closes the FK-CASCADE immutability hole: Postgres runs referential actions
     * bypassing RLS, so if a published version were deletable its ON DELETE CASCADE would wipe the
     * "immutable" child rows without the child guard ever firing.
     */
    public static function formVersionGuard(
        string $table,
        string $statusColumn = 'status',
        string $draftValue = 'draft',
        string $tenantColumn = 'tenant_id',
    ): void {
        self::execute(self::formVersionGuardSql($table, $statusColumn, $draftValue, $tenantColumn));
    }

    /**
     * The published-immutability guard for the three content child tables — form_sections, form_fields,
     * form_field_validations (form-versioning-schema-migration.md §2). Strict tenant SELECT (reading
     * published/superseded content must always work), plus INSERT/UPDATE/DELETE gated on the owning
     * `form_versions` row still being a `draft`. Emitted as the SOLE write policy per command so
     * Postgres's permissive-OR composition leaves it fully restrictive.
     */
    public static function draftChildGuard(
        string $table,
        string $parentTable = 'form_versions',
        string $fkColumn = 'form_version_id',
        string $parentStatusColumn = 'status',
        string $draftValue = 'draft',
        string $tenantColumn = 'tenant_id',
    ): void {
        self::execute(self::draftChildGuardSql($table, $parentTable, $fkColumn, $parentStatusColumn, $draftValue, $tenantColumn));
    }

    // ── Pure SQL generators (no database access — the unit-test surface) ─────────────────────

    /**
     * @return list<string>
     */
    public static function strictSql(string $table, string $tenantColumn = 'tenant_id'): array
    {
        self::assertIdentifier($table);
        self::assertIdentifier($tenantColumn);

        $match = self::tenantMatch($tenantColumn);

        return [
            ...self::enableAndForce($table),
            self::policy($table, 'tenant_select', 'SELECT', using: $match),
            self::policy($table, 'tenant_insert', 'INSERT', check: $match),
            self::policy($table, 'tenant_update', 'UPDATE', using: $match, check: $match),
            self::policy($table, 'tenant_delete', 'DELETE', using: $match),
        ];
    }

    /**
     * @return list<string>
     */
    public static function nullableGlobalSql(string $table, string $tenantColumn = 'tenant_id'): array
    {
        self::assertIdentifier($table);
        self::assertIdentifier($tenantColumn);

        $match = self::tenantMatch($tenantColumn);
        $readable = $match.' OR '.$tenantColumn.' IS NULL';

        return [
            ...self::enableAndForce($table),
            // SELECT is widened to include platform-global (NULL) rows …
            self::policy($table, 'tenant_select', 'SELECT', using: $readable),
            // … but every write stays strict: a tenant connection cannot create/alter a global row.
            self::policy($table, 'tenant_insert', 'INSERT', check: $match),
            self::policy($table, 'tenant_update', 'UPDATE', using: $match, check: $match),
            self::policy($table, 'tenant_delete', 'DELETE', using: $match),
        ];
    }

    /**
     * @return list<string>
     */
    public static function belongsToUserSql(string $table, string $userColumn = 'user_id'): array
    {
        self::assertIdentifier($table);
        self::assertIdentifier($userColumn);

        $match = sprintf('%s = %s', $userColumn, self::settingUuid(self::USER_SETTING));

        return [
            ...self::enableAndForce($table),
            self::policy($table, 'owner_select', 'SELECT', using: $match),
            self::policy($table, 'owner_insert', 'INSERT', check: $match),
            self::policy($table, 'owner_update', 'UPDATE', using: $match, check: $match),
            self::policy($table, 'owner_delete', 'DELETE', using: $match),
        ];
    }

    /**
     * `users` join-shape (multi-tenancy-rbac-design.md §6): a row is visible to itself and to
     * anyone who currently shares an active tenant membership with it. SELECT only — writes on a
     * user's own account are governed by application-layer authorization, so Increment B composes
     * the write policies (or a privileged path) rather than this helper. Emitted now for B to call.
     *
     * @return list<string>
     */
    public static function usersVisibilitySql(string $table = 'users'): array
    {
        self::assertIdentifier($table);

        $using = sprintf(
            'id = %s OR EXISTS ('
                .'SELECT 1 FROM tenant_users tu WHERE tu.user_id = %s.id '
                ."AND tu.tenant_id = %s AND tu.status = 'active')",
            self::settingUuid(self::USER_SETTING),
            $table,
            self::settingUuid(self::TENANT_SETTING)
        );

        return [
            ...self::enableAndForce($table),
            self::policy($table, 'users_visibility', 'SELECT', using: $using),
        ];
    }

    /**
     * Companion write policies for the `users` visibility shape (RBAC §6: user writes are app-layer
     * authorized, not tenant-scoped — but FORCE RLS still needs *some* write policy or all writes are
     * denied). INSERT permissive (registration/invite-placeholder run with no user context); UPDATE
     * and DELETE own-row (a user modifies only their own account). When $authRole is given, add
     * `TO <authRole>` permissive SELECT/UPDATE so the pre-auth login/reset path (which runs with no
     * context) can resolve and write a user — scoped to that role only, never the app role.
     *
     * @return list<string>
     */
    public static function usersWritePoliciesSql(string $table = 'users', ?string $authRole = null): array
    {
        self::assertIdentifier($table);

        // Writes on the global `users` identity table are governed by APPLICATION-LAYER authorization,
        // not tenant RLS (RBAC §6: user writes are "a user's own account", not a tenant operation).
        // FORCE RLS still needs a policy per command, so these are permissive — the SELECT visibility
        // policy is what enforces read isolation. Permissive writes also let central account-management
        // (Fortify profile/password/2FA, which run with no tenant context) update the user's own row,
        // and let the password-reset save succeed on the pre-auth connection.
        $statements = [
            self::policy($table, 'app_insert', 'INSERT', check: 'true'),
            self::policy($table, 'app_update', 'UPDATE', using: 'true', check: 'true'),
            self::policy($table, 'app_delete', 'DELETE', using: 'true'),
        ];

        if ($authRole !== null) {
            self::assertIdentifier($authRole);
            // The pre-auth login/reset path (RlsAwareUserProvider on the meridian_auth role) must
            // SELECT users with no context; the join-shape visibility policy fails closed, so add a
            // permissive SELECT scoped to that role only (writes are covered by the public policies).
            $statements[] = self::policy($table, 'auth_select', 'SELECT', using: 'true', role: $authRole);
        }

        return $statements;
    }

    /**
     * Super-admin cross-tenant carve-out (RBAC §9 / ADR-0002 §D3). Deliberately does NOT emit
     * ENABLE/FORCE ROW LEVEL SECURITY — it is an ADDITIVE permissive policy layered onto a table that
     * already carries its base shape's RLS+FORCE, never a standalone isolation shape. Each requested
     * command gets one permissive policy, scoped `TO $superAdminRole` and gated on the
     * app.is_superadmin_context GUC. The gate is a plain text equality, not the NULLIF(...)::uuid form:
     * an unset GUC yields NULL, and `NULL = 'true'` is NULL (not true) ⇒ inherently fail-closed with no
     * cast hazard. Because it is role-scoped, setting the GUC on any other connection grants nothing.
     *
     * @param  list<string>  $commands
     * @return list<string>
     */
    public static function superAdminBypassSql(string $table, string $superAdminRole, array $commands = ['SELECT']): array
    {
        self::assertIdentifier($table);
        self::assertIdentifier($superAdminRole);

        $gate = sprintf("current_setting(%s, true) = 'true'", self::quote(self::SUPERADMIN_SETTING));

        $statements = [];
        foreach ($commands as $command) {
            $statements[] = match (strtoupper($command)) {
                'SELECT' => self::policy($table, 'superadmin_select', 'SELECT', using: $gate, role: $superAdminRole),
                'INSERT' => self::policy($table, 'superadmin_insert', 'INSERT', check: $gate, role: $superAdminRole),
                'UPDATE' => self::policy($table, 'superadmin_update', 'UPDATE', using: $gate, check: $gate, role: $superAdminRole),
                'DELETE' => self::policy($table, 'superadmin_delete', 'DELETE', using: $gate, role: $superAdminRole),
                default => throw new InvalidArgumentException("Unsupported RLS command: {$command}"),
            };
        }

        return $statements;
    }

    /**
     * SQL for the {@see formVersionGuard()} shape. Strict SELECT/INSERT/UPDATE (UPDATE deliberately
     * unconstrained by status so publish can flip draft→published→superseded), plus a DELETE policy
     * that only matches a `draft` row — a published/superseded version is undeletable, which is what
     * keeps its ON DELETE CASCADE from reaching (and wiping) the immutable child rows.
     *
     * @return list<string>
     */
    public static function formVersionGuardSql(
        string $table,
        string $statusColumn = 'status',
        string $draftValue = 'draft',
        string $tenantColumn = 'tenant_id',
    ): array {
        self::assertIdentifier($table);
        self::assertIdentifier($statusColumn);
        self::assertIdentifier($tenantColumn);
        self::assertLiteral($draftValue);

        $match = self::tenantMatch($tenantColumn);
        $isDraft = sprintf('%s = %s', $statusColumn, self::quote($draftValue));

        return [
            ...self::enableAndForce($table),
            self::policy($table, 'tenant_select', 'SELECT', using: $match),
            self::policy($table, 'tenant_insert', 'INSERT', check: $match),
            // UPDATE stays strict (tenant-only) so the publish transaction can transition a version's
            // own status; the version ROW is mutable, its published CONTENT is frozen by the child guard.
            self::policy($table, 'tenant_update', 'UPDATE', using: $match, check: $match),
            // Only a draft version may be deleted (discarded). Blocking DELETE of a published/superseded
            // version closes the FK-CASCADE bypass — referential actions run bypassing RLS.
            self::policy($table, 'tenant_delete', 'DELETE', using: $match.' AND '.$isDraft),
        ];
    }

    /**
     * SQL for the {@see draftChildGuard()} shape. Strict tenant SELECT; INSERT/UPDATE/DELETE gated on
     * `tenant_id = ctx AND EXISTS (a form_versions row for this child whose status = 'draft')`. Never
     * gates SELECT — published/superseded content must stay readable. This is emitted as the ONLY
     * write policy per command, which (given Postgres OR-combines same-command permissive policies) is
     * what makes it fully restrictive; a child migration must therefore call ONLY this variant.
     *
     * @return list<string>
     */
    public static function draftChildGuardSql(
        string $table,
        string $parentTable = 'form_versions',
        string $fkColumn = 'form_version_id',
        string $parentStatusColumn = 'status',
        string $draftValue = 'draft',
        string $tenantColumn = 'tenant_id',
    ): array {
        self::assertIdentifier($table);
        self::assertIdentifier($parentTable);
        self::assertIdentifier($fkColumn);
        self::assertIdentifier($parentStatusColumn);
        self::assertIdentifier($tenantColumn);
        self::assertLiteral($draftValue);

        $match = self::tenantMatch($tenantColumn);
        $parentIsDraft = sprintf(
            'EXISTS (SELECT 1 FROM %s fv WHERE fv.id = %s.%s AND fv.%s = %s)',
            $parentTable,
            $table,
            $fkColumn,
            $parentStatusColumn,
            self::quote($draftValue),
        );
        $writeGuard = $match.' AND '.$parentIsDraft;

        return [
            ...self::enableAndForce($table),
            self::policy($table, 'tenant_select', 'SELECT', using: $match),
            self::policy($table, 'draft_insert', 'INSERT', check: $writeGuard),
            self::policy($table, 'draft_update', 'UPDATE', using: $writeGuard, check: $writeGuard),
            self::policy($table, 'draft_delete', 'DELETE', using: $writeGuard),
        ];
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $statements
     */
    private static function execute(array $statements): void
    {
        foreach ($statements as $statement) {
            DB::statement($statement);
        }
    }

    /**
     * @return list<string>
     */
    private static function enableAndForce(string $table): array
    {
        return [
            "ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;",
            // Mandatory (ADR-0002 §D2): applies RLS even to the table owner / app DB role.
            "ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;",
        ];
    }

    private static function tenantMatch(string $tenantColumn): string
    {
        return sprintf('%s = %s', $tenantColumn, self::settingUuid(self::TENANT_SETTING));
    }

    /**
     * A session setting read back as a uuid, fail-closed. The `NULLIF(..., '')` is essential, not
     * cosmetic: a custom GUC that has been SET and then cleared within a session reads back as ''
     * (empty string), NOT NULL — and `''::uuid` raises an error, which would turn the fail-closed
     * default ("no rows visible") into a hard query failure. NULLIF collapses both "never set" (NULL)
     * and "set then cleared" ('') to NULL, which the `= NULL` comparison treats as matching no row.
     */
    private static function settingUuid(string $setting): string
    {
        return sprintf("NULLIF(current_setting(%s, true), '')::uuid", self::quote($setting));
    }

    private static function policy(
        string $table,
        string $suffix,
        string $command,
        ?string $using = null,
        ?string $check = null,
        ?string $role = null,
    ): string {
        $sql = sprintf('CREATE POLICY %s_%s ON %s FOR %s', $table, $suffix, $table, $command);

        // `TO <role>` scopes the policy to one DB role (permissive policies for one role never widen
        // another). Must precede USING/WITH CHECK in the CREATE POLICY grammar.
        if ($role !== null) {
            $sql .= sprintf(' TO %s', $role);
        }

        if ($using !== null) {
            $sql .= sprintf(' USING (%s)', $using);
        }

        if ($check !== null) {
            $sql .= sprintf(' WITH CHECK (%s)', $check);
        }

        return $sql.';';
    }

    private static function quote(string $literal): string
    {
        return "'".str_replace("'", "''", $literal)."'";
    }

    /**
     * Table/column identifiers come only from migration source (never user input), but validate
     * anyway so a typo fails loudly and static analysis has no injection surface to flag.
     */
    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException("Unsafe SQL identifier: {$identifier}");
        }
    }

    private static function assertLiteral(string $literal): void
    {
        if (preg_match('/^[a-z0-9_]+$/i', $literal) !== 1) {
            throw new InvalidArgumentException("Unsafe SQL literal: {$literal}");
        }
    }
}
