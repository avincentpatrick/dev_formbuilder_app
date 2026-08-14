<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Tenancy\TenantScopedTables;

/**
 * What a tenant-scoped read of a table actually returns (Phase 4, P2a — ADR-0017 §D3).
 *
 * The question this vocabulary answers is narrower than "is this table tenant-scoped", and the
 * difference is the whole point: a future per-tenant extraction relies on Row-Level Security as its
 * filter rather than on a `where` clause, so what matters is **whether RLS alone returns exactly the
 * tenant's rows, a superset of them, or nothing meaningful at all.**
 *
 *   - {@see TenantScoped}   — RLS returns EXACTLY this tenant's rows. Safe to read with no predicate.
 *   - {@see PlatformShared} — the SELECT policy is widened with `OR tenant_id IS NULL`, so RLS returns
 *     the tenant's rows PLUS the platform catalog. A no-predicate read here is a SUPERSET, and the
 *     platform half is not the tenant's to receive.
 *   - {@see NotExtracted}   — everything else: central tables, framework plumbing, the RLS-exempt
 *     discriminator tables, and the two RLS'd tables keyed on a person rather than an organisation.
 *
 * Three cases and not five. A `central` / `framework` / `referenced` split reads as more precise and
 * has no consumer — nothing branches on it, and a distinction nothing acts on is a distinction that
 * drifts unnoticed. The membership lists and the rationale for every exception live in
 * {@see TenantScopedTables}.
 */
enum TenantTableClass: string
{
    case TenantScoped = 'tenant_scoped';
    case PlatformShared = 'platform_shared';
    case NotExtracted = 'not_extracted';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Whether reading this table under nothing but a tenant GUC returns MORE than the tenant owns.
     *
     * True only for {@see PlatformShared}. This is the property that makes the widened shape unsafe to
     * hand to a caller that believes RLS is its filter.
     */
    public function returnsSuperset(): bool
    {
        return $this === self::PlatformShared;
    }
}
