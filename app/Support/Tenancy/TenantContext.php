<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Support\Facades\DB;
use Throwable;

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

    /**
     * Restore the PHP-side mirror to a previously captured pair, WITHOUT touching the database.
     * The exact inverse of {@see flush()}, and deliberately not routed through {@see set()}: set()
     * would issue two session-scoped `set_config` round-trips, which is both wasteful per job and
     * wrong (it would leave a session-scoped GUC behind on a worker's long-lived connection).
     *
     * Callers: App\Listeners\Queue\ScopeTenantContextToJob (ADR-0007 §D4), which saves the ambient mirror
     * before a job runs and restores it after, and {@see runFor()} on the two paths where the database has
     * already reverted itself and only the mirror is left stale. It read "sole caller" until M3 added the
     * second — recorded because a docblock that names its only caller goes stale the moment it gains another. That matters under the `sync` driver —
     * every current CI job — where the queue events fire INLINE in the caller's stack, so a blind
     * flush would wipe the surrounding request's context mid-request.
     */
    public static function restoreMirror(?string $tenantId, ?string $userId): void
    {
        self::$tenantId = $tenantId;
        self::$userId = $userId;
    }

    /**
     * Run $work under $tenantId's own context, transaction-scoped, and restore whatever was there
     * before — so a caller does not have to have established the right context first.
     *
     * THE COMPOSITION THIS CLASS WAS MISSING. Every caller that needs "act for THIS tenant regardless
     * of what the ambient request or worker left behind" has had to hand-roll four steps in the right
     * order: save the mirror, open a transaction (because applyLocal() is `SET LOCAL` and a SILENT
     * NO-OP outside one), apply, and restore in a `finally`. {@see \App\Listeners\Auth\SendWelcomeEmail}
     * (`isMemberOf`), {@see \App\Services\Admin\ImpersonationService} and
     * {@see \App\Services\Admin\SuperAdminService} each write it out by hand; the two fan-out
     * dispatchers (M3) are the first callers of the extraction. The five hand-rolled sites are
     * DELIBERATELY NOT retrofitted here — each is correct, and rewriting a correct tenant-boundary
     * call site is its own increment with its own gate run.
     *
     * ⚠️ THE USER ID IS CARRIED THROUGH ONLY WHEN THE TENANT IS UNCHANGED, and is null otherwise. A
     * user belongs to a workspace, so carrying an id across a tenant switch would assert a membership
     * that may not exist; null is the fail-closed value, since an unset `app.current_user_id` makes a
     * user-keyed policy match zero rows rather than all rows. A caller whose work needs a specific user
     * under a DIFFERENT tenant must say which — and none does today.
     *
     * ⚠️ THE RESTORE IS AFTER THE TRANSACTION, NOT IN A `finally` INSIDE IT, AND THAT IS DELIBERATE.
     * The obvious shape — apply, work, restore in a `finally` — issues SQL on the way out of a FAILURE,
     * where the connection may be in Postgres' aborted-transaction state; the restore would then throw
     * and REPLACE the exception that caused it, destroying the diagnostic. So the three exits are
     * separated: on a throw only the PHP mirror is put back (the database has already reverted itself,
     * by rollback or by ROLLBACK TO SAVEPOINT); on a NESTED success the GUC really must be re-issued,
     * because a `SET LOCAL` inside a savepoint SURVIVES its release and would otherwise hand the rest of
     * the enclosing transaction a tenant it never asked for — the H12a sweep is exactly that case; and on
     * an outermost success the COMMIT has already discarded the LOCAL values, so the mirror is all that
     * is left and two round-trips are saved on the request path.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public static function runFor(string $tenantId, callable $work): mixed
    {
        $savedTenant = self::$tenantId;
        $savedUser = self::$userId;

        try {
            $result = DB::transaction(static function () use ($tenantId, $savedTenant, $savedUser, $work) {
                self::applyLocal($tenantId, $savedTenant === $tenantId ? $savedUser : null);

                return $work();
            });
        } catch (Throwable $e) {
            // The DATABASE has already put itself right: a rollback discards this transaction's `SET LOCAL`,
            // and a ROLLBACK TO SAVEPOINT reverts it to what the enclosing transaction had. Only the PHP
            // mirror is left stale, and restoring it touches no connection — which is the point. Issuing SQL
            // here would run against a connection that may be in Postgres' aborted-transaction state, where
            // every statement fails, and THAT failure would replace the real exception. It is the hazard
            // {@see \App\Listeners\Queue\ScopeTenantContextToJob} solves by never touching the database on
            // the way out.
            self::restoreMirror($savedTenant, $savedUser);

            throw $e;
        }

        if (DB::transactionLevel() > 0) {
            // NESTED, and this is the branch the whole method exists for: DB::transaction() opened a
            // SAVEPOINT, and a `SET LOCAL` issued inside one SURVIVES its release — so without this the
            // enclosing transaction would silently continue under a tenant it never asked for.
            self::applyLocal($savedTenant, $savedUser);
        } else {
            // Outermost: COMMIT already discarded the LOCAL values, so any session-scoped context the HTTP
            // middleware set is back by itself and only the mirror needs putting right. Two `set_config`
            // round-trips saved on the request path, where this runs once per submission.
            self::restoreMirror($savedTenant, $savedUser);
        }

        return $result;
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
