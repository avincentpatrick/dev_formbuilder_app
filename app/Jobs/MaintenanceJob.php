<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueName;
use App\Jobs\Maintenance\VerifyCustomDomainsJob;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Generator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * The base class for CROSS-TENANT work (ADR-0007 §D3) — rollups, reapers and sweeps. Phase 3 is the
 * first phase to need any, and this is the one auditable shape for it, so that twelve verticals do
 * not each invent their own answer to "which code may run without a tenant".
 *
 * The rules, all three binding:
 *   1. Runs with NO tenant context. The §D4 listener has already flushed the statics; nothing here
 *      re-establishes them.
 *   2. May touch ONLY RLS-exempt tables — `tenants`, `domains`, the queue's own tables, and `plans`
 *      when it exists. A tenant-scoped query here returns ZERO ROWS rather than failing, which is why
 *      scripts/job-payload-lint.php checks this structurally instead of trusting review.
 *   3. Its sole tenant-touching side effect is dispatching one {@see TenantAwareJob} per active
 *      tenant — see {@see activeTenants()}. A write confined to the rule-2 exempt tables is NOT a
 *      tenant-touching side effect, so a sweep whose entire subject matter lives there satisfies this
 *      rule vacuously and correctly does its work INLINE rather than fanning out. H22a's
 *      {@see VerifyCustomDomainsJob} is the first of that shape (it operates on
 *      `domains`); a fan-out there would queue a job per tenant to re-read a table no tenant GUC
 *      affects. Stated here because otherwise every future reader flags it as a violation.
 *
 * WHY A CROSS-TENANT TRANSACTION IS NOT AN OPTION. It is not merely discouraged, it is INEXPRESSIBLE
 * in this architecture: the RLS context is a scalar GUC and TenantIsolation::tenantMatch() is flat
 * equality with no set or any-tenant mode. The only widenings that exist are `nullableGlobal`
 * (SELECT-only) and the role-gated `superAdminBypass`, neither of which is a tenant set. So the
 * fan-out is not a stylistic preference — it is the only shape the database permits.
 *
 * This also satisfies the standing rule at technical-architecture.md:184 that a scheduled task
 * "dispatches to the queue rather than running inline".
 */
#[Queue(QueueName::ScheduledMaintenance)]
abstract class MaintenanceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * §D7. A sweep is not retried: a missed tick is harmless because the next one subsumes it, while a
     * retried fan-out is actively harmful (duplicate children). Correctness comes from idempotence
     * and from {@see middleware()}'s overlap lock, not from retries.
     */
    public int $tries = 1;

    /**
     * Longer than a tenant job's 60s — a sweep is O(tenants), not O(1). Still well under the
     * database connection's retry_after (120) only because $tries = 1 means a second reservation
     * cannot produce a second execution; the overlap lock is the real guard.
     */
    public int $timeout = 300;

    /**
     * The sweep itself. Runs with no tenant context — see the class docblock for what that permits.
     */
    abstract protected function sweep(): void;

    final public function handle(): void
    {
        // Belt and braces with the §D4 listener. The listener already cleared these on the way in,
        // but a MaintenanceJob is precisely the class of job for which a stale static would be
        // invisible (it makes no tenant-scoped reads of its own, so it would not fail — it would
        // silently hand a wrong tenant id to whatever it dispatches).
        TenantContext::flush();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->sweep();
    }

    /**
     * §D7. dontRelease() is MANDATORY, not decorative: WithoutOverlapping's constructor defaults
     * $releaseAfter to 0 — NOT null — so without it an overlapping tick calls release(0), and with
     * $tries = 1 the job lands in failed_jobs within milliseconds. dontRelease() makes an overlapping
     * tick a silent skip, which is the correct cron semantics.
     *
     * expireAfter() bounds the lock so a hard crash (SIGKILL, OOM) cannot strand it forever and
     * permanently wedge the sweep. Set above $timeout so it can never expire under a live job.
     *
     * @return array<int, object>
     */
    final public function middleware(): array
    {
        return [
            (new WithoutOverlapping(static::class))
                ->dontRelease()
                ->expireAfter($this->timeout * 2),
        ];
    }

    /**
     * The §D3 fan-out enumeration: ACTIVE tenants only, so a suspended tenant is never fanned out to
     * in the first place (§D13 handles the race where a tenant is suspended after dispatch).
     *
     * Legal with no GUC because `tenants` is RLS-exempt — it is the discriminator table itself.
     * Routed through Tenant::scopeActive() so this and §D13's per-job guard cannot drift apart.
     *
     * lazyById() rather than cursor(): on libpq a cursor buys no server-side streaming (the result
     * set is buffered client-side regardless), whereas lazyById() genuinely chunks.
     *
     * @return Generator<int, Tenant>
     */
    final protected function activeTenants(): Generator
    {
        /** @var LazyCollection<int, Tenant> $tenants */
        $tenants = Tenant::query()->active()->lazyById();

        yield from $tenants;
    }

    /**
     * §D12, minus the tenant id — a sweep has no tenant. Never re-enters tenant context.
     */
    final public function failed(?Throwable $e): void
    {
        Log::error('Maintenance job failed.', [
            'job' => static::class,
            'queue' => QueueName::ScheduledMaintenance->value,
            'attempts' => $this->job?->attempts() ?? 0,
            'exception_class' => $e === null ? null : $e::class,
        ]);
    }
}
