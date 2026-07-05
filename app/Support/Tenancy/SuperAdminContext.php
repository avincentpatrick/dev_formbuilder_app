<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Opens the elevated super-admin RLS context (ADR-0002 §D3, RBAC §9) — the single GUC
 * `app.is_superadmin_context` the `superadmin_bypass` policies read. This is the super-admin analogue
 * of {@see TenantContext}: it sets one Postgres session variable that widens what the elevated
 * `meridian_superadmin` role can see, and only while the variable is 'true'.
 *
 * applyLocal() (is_local = true) is the ONLY setter used in practice: it lives for the current
 * TRANSACTION on the `pgsql_superadmin` connection and is discarded on commit/rollback, so a pooled
 * elevated connection can never carry an "I am super-admin" flag into a later operation. The narrow
 * App\Services\Admin\SuperAdminService opens it inside a transaction and never elsewhere.
 *
 * apply()/forget() (session scope) exist for symmetry with TenantContext but are unused in B2c — the
 * elevated connection is never the HTTP request connection.
 */
final class SuperAdminContext
{
    public const SUPERADMIN_SETTING = 'app.is_superadmin_context';

    /** The dedicated elevated connection; the only one the bypass policies are scoped `TO`. */
    public const CONNECTION = 'pgsql_superadmin';

    /**
     * Transaction-scoped elevation (is_local = true). Safe under pooling; auto-cleared on commit/rollback.
     * MUST be called inside a transaction on {@see CONNECTION} to have any effect.
     */
    public static function applyLocal(?string $connection = null): void
    {
        self::set(true, local: true, connection: $connection);
    }

    /** Session-scoped elevation (is_local = false). Present for symmetry; pair with forget(). Unused in B2c. */
    public static function apply(?string $connection = null): void
    {
        self::set(true, local: false, connection: $connection);
    }

    /** Clear the elevation flag (reset to NULL ⇒ fail-closed again). */
    public static function forget(?string $connection = null): void
    {
        self::set(false, local: false, connection: $connection);
    }

    private static function set(bool $on, bool $local, ?string $connection): void
    {
        // Clearing binds SQL NULL (not ''), consistent with TenantContext: set_config(name, NULL, …)
        // RESETs the GUC so current_setting(name, true) returns NULL and the `= 'true'` gate is false.
        // is_local is inlined as a trusted literal (pdo_pgsql won't reliably coerce a bound PHP bool).
        $scope = $local ? 'true' : 'false';
        $value = $on ? 'true' : null;

        DB::connection($connection ?? self::CONNECTION)
            ->statement("SELECT set_config(?, ?, {$scope})", [self::SUPERADMIN_SETTING, $value]);
    }
}
