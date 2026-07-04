<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Support\Facades\DB;

/**
 * Establishes the per-request/per-transaction Postgres session variables that RLS policies read
 * (ADR-0002 §D2/§D3). This is the bridge between "the app resolved a tenant" and "the database
 * will enforce it" — without it, every tenant-scoped table fails closed (zero rows).
 *
 * Two setters, one hazard:
 *   - applyLocal() uses set_config(..., is_local = true): the value lives only for the current
 *     TRANSACTION and is discarded on commit/rollback. This is leak-proof under connection pooling
 *     (a reused connection cannot carry a stale value into the next request) and is what tests and
 *     transaction-wrapped jobs use.
 *   - apply() uses is_local = false (session scope) for the HTTP middleware path, where Laravel does
 *     not wrap the request in a transaction. It MUST be paired with forget() on request termination
 *     so a value never survives onto a pooled/persistent connection. See ADR-0002 §D2 for the
 *     pooled-connection sharp edge this guards against.
 *
 * set_config() is used rather than `SET LOCAL app.current_tenant_id = '…'` so the value binds as a
 * parameter (no string interpolation of the uuid) and so is_local is a first-class argument.
 */
final class TenantContext
{
    public const TENANT_SETTING = 'app.current_tenant_id';

    public const USER_SETTING = 'app.current_user_id';

    /**
     * The PHP-side mirror of the DB session variables. The database is the enforcement authority
     * (RLS); this copy exists only so the {@see BelongsToTenant} convenience
     * layer can inject the same predicate and auto-fill tenant_id on create without a round-trip.
     */
    private static ?string $tenantId = null;

    private static ?string $userId = null;

    /**
     * Transaction-scoped context (is_local = true). Safe under pooling; auto-cleared on commit/rollback.
     */
    public static function applyLocal(?string $tenantId, ?string $userId = null, ?string $connection = null): void
    {
        self::set($tenantId, $userId, local: true, connection: $connection);
    }

    /**
     * Session-scoped context (is_local = false) for the HTTP path. Pair with forget() on terminate.
     */
    public static function apply(?string $tenantId, ?string $userId = null, ?string $connection = null): void
    {
        self::set($tenantId, $userId, local: false, connection: $connection);
    }

    /**
     * Clear both variables from the current session (reset to NULL ⇒ fail-closed) and the PHP mirror.
     */
    public static function forget(?string $connection = null): void
    {
        self::set(null, null, local: false, connection: $connection);
    }

    public static function currentTenantId(): ?string
    {
        return self::$tenantId;
    }

    public static function currentUserId(): ?string
    {
        return self::$userId;
    }

    public static function hasTenant(): bool
    {
        return self::$tenantId !== null;
    }

    /**
     * Clear only the PHP-side mirror, without touching the database. For test isolation: the DB
     * session variable is transaction-scoped (auto-reset on rollback), but this static is not.
     */
    public static function flush(): void
    {
        self::$tenantId = null;
        self::$userId = null;
    }

    private static function set(?string $tenantId, ?string $userId, bool $local, ?string $connection): void
    {
        // Clearing binds SQL NULL (not ''): set_config(name, NULL, …) RESETS the GUC so that
        // current_setting(name, true) returns NULL again. Storing '' would be a trap — the policy's
        // `current_setting(...)::uuid` would then try to cast '' to uuid and ERROR, turning the
        // fail-closed default ("no rows") into a hard query failure. NULL::uuid is NULL, which the
        // `tenant_id = NULL` predicate treats as no-match — the intended fail-closed behavior.
        //
        // is_local is inlined as a trusted literal rather than bound: pdo_pgsql does not reliably
        // coerce a bound PHP bool to a Postgres boolean function argument.
        $scope = $local ? 'true' : 'false';
        $db = DB::connection($connection);

        $db->statement("SELECT set_config(?, ?, {$scope})", [self::TENANT_SETTING, $tenantId]);
        $db->statement("SELECT set_config(?, ?, {$scope})", [self::USER_SETTING, $userId]);

        self::$tenantId = $tenantId;
        self::$userId = $userId;
    }
}
