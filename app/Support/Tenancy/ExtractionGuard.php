<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Exceptions\Tenancy\ExtractionGuardException;
use Illuminate\Support\Facades\DB;

/**
 * The two preconditions any code that treats Row-Level Security as its filter must assert (Phase 4, P2a —
 * ADR-0017 §D4).
 *
 * ── WHY THESE ARE CODE AND NOT A CONVENTION ────────────────────────────────────────────────────────────
 * A tenant-scoped read with no `where` clause is a correct and desirable shape here: RLS is the isolation,
 * and a hand-written predicate would put the guarantee back in PHP where ADR-0002 spent an entire decision
 * getting it out of. But it moves the filter into two things the calling code does not state and cannot
 * see — **which role the connection authenticated as**, and **whether the GUC actually took**. Get either
 * wrong and the query still succeeds, still returns rows (or none), and still exits zero.
 *
 * Both failures are already documented in this codebase, in four separate docblocks, which is the argument
 * for a guard rather than a fifth comment.
 */
final class ExtractionGuard
{
    /**
     * Refuse to proceed on a connection whose role is not subject to RLS.
     *
     * ⚠️ THIS IS THE ONE THAT PREVENTS A FULL-DATABASE DUMP LABELLED AS ONE TENANT. "RLS is the filter"
     * makes the filter a property of the ROLE, not of the code: `meridian` is SUPERUSER and BYPASSRLS, so
     * every policy is ignored and a tenant-scoped read returns EVERY tenant's rows — with no exception, no
     * warning, and no `where` clause anywhere to notice. A `.env` pointing `DB_USERNAME` at the privileged
     * role is all it takes, and `config/database.php` already warns that request-path use of
     * `pgsql_privileged` is a bug. Nothing enforced it until here.
     *
     * The same assertion exists in `CrossTenantIsolationTest`, where it stops the fuzz pack passing
     * vacuously. It belongs in production code for the same reason.
     *
     * @throws ExtractionGuardException
     */
    public static function assertRlsSubjectRole(?string $connection = null): void
    {
        $db = DB::connection($connection);

        // Cast to int IN SQL. pdo_pgsql returns booleans as the strings 't'/'f', and `(bool) 'f'` is TRUE —
        // so a PHP-side cast would read a BYPASSRLS role as compliant and this guard would invert.
        $role = $db->selectOne(
            'select rolname, rolsuper::int as is_super, rolbypassrls::int as bypass_rls
             from pg_roles where rolname = current_user'
        );

        if ($role === null) {
            throw ExtractionGuardException::roleUnreadable();
        }

        if ((int) $role->is_super !== 0 || (int) $role->bypass_rls !== 0) {
            throw ExtractionGuardException::roleBypassesRls((string) $role->rolname);
        }
    }

    /**
     * Refuse to proceed unless the database session genuinely carries the tenant being acted for.
     *
     * ⚠️ THIS IS THE ONE THAT PREVENTS AN EMPTY, SUCCESSFUL RESULT. {@see TenantContext::applyLocal()} is
     * `set_config(..., is_local = true)` — i.e. `SET LOCAL`, which **outside a transaction is a silent
     * no-op**. The GUC reads back empty on the very next round trip, every RLS policy then matches zero
     * rows (fail-closed, by design), and the caller sees a clean run over an empty result set. Nothing
     * throws. `TenantAwareJob`, `ImpersonationService` and the two exporters each carry a docblock about
     * this; a read-back is what turns those warnings into something the build can enforce.
     *
     * Reading the GUC back also catches the cheaper mistakes for free: a caller that set one tenant and
     * acted for another, and a context that was never established at all.
     *
     * @throws ExtractionGuardException
     */
    public static function assertContextEstablished(string $tenantId, ?string $connection = null): void
    {
        $db = DB::connection($connection);

        $row = $db->selectOne(
            'select current_setting(?, true) as tenant_id',
            [TenantContext::TENANT_SETTING]
        );

        $actual = $row?->tenant_id;

        // An unset GUC reads back NULL; one that was set and then reset reads back the EMPTY STRING. Both
        // mean "no context", and both must be refused — the distinction only matters to the message.
        if ($actual === null || $actual === '') {
            throw ExtractionGuardException::contextMissing($tenantId);
        }

        if ((string) $actual !== $tenantId) {
            throw ExtractionGuardException::contextMismatch($tenantId, (string) $actual);
        }
    }
}
