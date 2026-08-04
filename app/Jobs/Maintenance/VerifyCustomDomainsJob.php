<?php

declare(strict_types=1);

namespace App\Jobs\Maintenance;

use App\Jobs\MaintenanceJob;
use App\Jobs\TenantAwareJob;
use App\Services\Tenancy\CustomDomainService;

/**
 * The custom-domain verification sweep (H22a / ADR-0012) — cross-tenant, so a {@see MaintenanceJob}.
 *
 * ⚠️ THE FIRST MaintenanceJob IN THIS CODEBASE THAT DOES ITS WORK INLINE RATHER THAN FANNING OUT, and
 * that is correct rather than a violation. Read the base class's three rules in order:
 *
 *   Rule 2 explicitly permits `domains` — it is one of the named RLS-exempt tables, because it is the
 *   table read BEFORE any tenant context exists in order to decide which tenant a request belongs to.
 *   Rule 3 says the sole TENANT-TOUCHING side effect is one {@see TenantAwareJob} per active tenant. A
 *   write confined to an exempt table is not tenant-touching, so rule 3 is satisfied vacuously rather
 *   than broken.
 *
 * A fan-out here would be strictly worse, not merely redundant: the child would re-read an RLS-exempt
 * table under a GUC that does nothing for it, and every tenant with no custom domains — which is all of
 * them, initially — would get a queued job on every tick.
 *
 * ⚠️ THE BATCH BOUND IS NOT AN OPTIMISATION. `dns_get_record()` takes NO timeout parameter, so a batch
 * of dead nameservers costs roughly (attempts × servers × 5s) EACH against this job's 300s budget. With
 * `$tries = 1` inherited from the base, blowing that budget does not retry — it lands in `failed_jobs`,
 * on every tick, forever. {@see CustomDomainService::sweep()} bounds the batch and orders it
 * oldest-checked-first so the bound is fair and nothing starves.
 *
 * Per-domain failures are swallowed inside the service loop for the same reason: one tenant's broken
 * zone must never abort the pass. Only infrastructure failure (the database) should be able to fail
 * this job.
 *
 * Every-fifteen-minutes rather than every-five (routes/console.php): a tenant who has just published the
 * TXT record can trigger an immediate check from the API instead of waiting, and fifteen keeps DNS
 * query volume clear of the two existing five-minute sweeps.
 */
final class VerifyCustomDomainsJob extends MaintenanceJob
{
    protected function sweep(): void
    {
        app(CustomDomainService::class)->sweep();
    }
}
