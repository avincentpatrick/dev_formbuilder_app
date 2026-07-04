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
 * Four policy shapes exist across the schema; this class emits all of them (ADR-0002 §D2,
 * multi-tenancy-rbac-design.md §6). Two are exercised behaviorally in Increment A (strict,
 * nullable-global); the other three are emitted + unit-tested now and fuzz-tested when their
 * real tables land (users/user_ui_preferences → Increment B, form_versions → Increment D).
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
     * Keyed on app.current_user_id rather than app.current_tenant_id. Emitted now; applied in B.
     */
    public static function belongsToUser(string $table, string $userColumn = 'user_id'): void
    {
        self::execute(self::belongsToUserSql($table, $userColumn));
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

        $match = sprintf(
            "%s = current_setting('%s', true)::uuid",
            $userColumn,
            self::USER_SETTING
        );

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
            'id = current_setting(%s, true)::uuid OR EXISTS ('
                .'SELECT 1 FROM tenant_users tu WHERE tu.user_id = %s.id '
                ."AND tu.tenant_id = current_setting(%s, true)::uuid AND tu.status = 'active')",
            self::quote(self::USER_SETTING),
            $table,
            self::quote(self::TENANT_SETTING)
        );

        return [
            ...self::enableAndForce($table),
            self::policy($table, 'users_visibility', 'SELECT', using: $using),
        ];
    }

    /**
     * Draft-guard: strict tenant isolation PLUS a published-immutability guard that blocks UPDATE
     * and DELETE of any row already published (form_versions, Increment D). Emitted now; applied in D.
     *
     * @return list<string>
     */
    public static function draftGuardSql(
        string $table,
        string $statusColumn = 'status',
        string $publishedValue = 'published',
        string $tenantColumn = 'tenant_id',
    ): array {
        self::assertIdentifier($table);
        self::assertIdentifier($statusColumn);
        self::assertIdentifier($tenantColumn);
        self::assertLiteral($publishedValue);

        $match = self::tenantMatch($tenantColumn);
        $notPublished = sprintf("%s <> '%s'", $statusColumn, $publishedValue);

        return [
            ...self::enableAndForce($table),
            self::policy($table, 'tenant_select', 'SELECT', using: $match),
            self::policy($table, 'tenant_insert', 'INSERT', check: $match),
            // A published row is immutable: it must be unpublished (USING) and stay tenant-scoped (CHECK).
            self::policy($table, 'tenant_update', 'UPDATE', using: $match.' AND '.$notPublished, check: $match),
            self::policy($table, 'tenant_delete', 'DELETE', using: $match.' AND '.$notPublished),
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
        return sprintf('%s = current_setting(%s, true)::uuid', $tenantColumn, self::quote(self::TENANT_SETTING));
    }

    private static function policy(
        string $table,
        string $suffix,
        string $command,
        ?string $using = null,
        ?string $check = null,
    ): string {
        $sql = sprintf('CREATE POLICY %s_%s ON %s FOR %s', $table, $suffix, $table, $command);

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
