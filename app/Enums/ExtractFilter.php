<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Tenancy\TenantScopedTables;

/**
 * WHAT restricts a table's rows to one tenant during an extraction (Phase 4, P2b — ADR-0018 §D2).
 *
 * This is not a restatement of {@see TenantTableClass}. That enum answers "what does a tenant-scoped read
 * RETURN"; this one answers "what must the extractor DO about it", and the two do not line up one-to-one:
 * `domains` classifies as {@see TenantTableClass::NotExtracted} because RLS does not reach it, and is
 * nonetheless extracted — under a hand-written predicate, because that is the only filter it has.
 *
 * ⚠️ THE ORDER OF THESE CASES IS THE ORDER OF DECREASING GUARANTEE, and the extractor records the mode it
 * used per table in the manifest for exactly that reason. {@see Rls} is enforced by the database and cannot
 * be forgotten by a caller; {@see PredicateOnly} is enforced by one `where` clause in PHP and nothing else.
 * A reader of an extract is entitled to know which of those produced each file.
 */
enum ExtractFilter: string
{
    /**
     * Row-Level Security alone. The table is {@see TenantScopedTables::STRICT}: `FORCE ROW LEVEL SECURITY`
     * is on and the SELECT policy is plain equality against `app.current_tenant_id`, so a no-predicate read
     * returns exactly this tenant's rows. Adding a `where tenant_id = ?` here would be harmless and is
     * deliberately NOT done — ADR-0002 spent a whole decision moving that guarantee out of PHP.
     */
    case Rls = 'rls';

    /**
     * Row-Level Security PLUS an explicit `tenant_id = ?`, because RLS over-selects here.
     *
     * The six {@see TenantScopedTables::NULLABLE_GLOBAL} tables widen their SELECT policy with
     * `OR tenant_id IS NULL`, so the tenant's rows arrive mixed with the platform catalog
     * ({@see TenantScopedTables::rlsReturnsSuperset()}). The predicate is the filter here; RLS is the
     * backstop under it.
     */
    case RlsAndPredicate = 'rls_and_predicate';

    /**
     * Row-Level Security via the JOIN-shape `users` policy — the acting user OR an ACTIVE co-tenant —
     * rather than plain equality against the tenant GUC.
     *
     * ⚠️ IT IS DATABASE-ENFORCED AND IT IS STILL NOT THE SAME GUARANTEE, which is why it is its own case
     * rather than folded into {@see self::Rls}. Equality returns every row carrying the tenant's id; this
     * returns every row REACHABLE from an active membership, and the difference is the whole of ADR-0018
     * §D4: an invited-but-not-yet-active member is referenced by extracted rows and is not himself
     * extracted. A manifest that called this `rls` would be describing a filter the reader would then
     * reason about incorrectly.
     */
    case RlsUserJoin = 'rls_user_join';

    /**
     * An explicit predicate in PHP and NO ROW-LEVEL SECURITY AT ALL — `domains.tenant_id = ?` and
     * `tenants.id = ?`.
     *
     * ⚠️ Why `domains` is unprotected is written into {@see TenantScopedTables::UNPROTECTED_TENANT_TABLES}:
     * it is read to decide WHICH TENANT a request is, so scoping it by tenant would be circular. `tenants`
     * is the same shape one level up — it is the table the discriminator points AT. Every other filter in
     * this enum survives a caller forgetting to write it. This one does not: delete the `where` clause and
     * the extract silently contains every workspace on the installation.
     */
    case PredicateOnly = 'predicate_only';

    /**
     * Whether the database would still isolate this table if the extractor's `where` clause were deleted.
     *
     * False only for {@see self::PredicateOnly}. The manifest reports this per table so that "which parts
     * of this artefact rest on application code" is answerable without reading the extractor.
     */
    public function isDatabaseEnforced(): bool
    {
        return $this !== self::PredicateOnly;
    }
}
