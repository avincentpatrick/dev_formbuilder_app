<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\QueueName;
use App\Jobs\TenantAwareJob;
use App\Listeners\Queue\ScopeTenantContextToJob;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The async execution substrate's wiring (ADR-0007). Two responsibilities, both of which must exist
 * before any job runs:
 *
 *   1. §D4 — the worker-edge tenant-context listeners ({@see ScopeTenantContextToJob}).
 *   2. §D9 — the per-tenant fairness limiter, which closes Risk R6.
 *
 * A dedicated provider rather than AppServiceProvider::boot(), matching this repo's convention of
 * focused, domain-named providers (ExpressionServiceProvider, ValidationServiceProvider,
 * SubmissionServiceProvider). There is no EventServiceProvider in this application.
 *
 * Note the short name shadows Illuminate\Queue\QueueServiceProvider; harmless, because
 * bootstrap/providers.php registers fully-qualified class strings.
 */
final class QueueServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerContextHygieneListeners();
        $this->registerFairnessLimiter();
    }

    /**
     * §D4. Registered via Event::listen rather than the Queue facade because QueueManager exposes
     * before()/after() helpers but has no attempted() — and JobAttempted is the only after-edge that
     * fires on the failure path too. {@see ScopeTenantContextToJob::leaving()}
     */
    private function registerContextHygieneListeners(): void
    {
        Event::listen(JobProcessing::class, [ScopeTenantContextToJob::class, 'entering']);
        Event::listen(JobAttempted::class, [ScopeTenantContextToJob::class, 'leaving']);
    }

    /**
     * §D9 — a per-tenant, per-queue ceiling on job executions started per minute.
     *
     * Illuminate\Queue\Middleware\RateLimited resolves the limiter by name and invokes it with the
     * JOB INSTANCE (not an HTTP Request, as the RateLimiter::for() closures in AppServiceProvider
     * receive). On limit it calls $job->release(), i.e. it DEFERS rather than rejects — the correct
     * semantics for a queue, and the reason TenantAwareJob uses retryUntil() instead of $tries.
     *
     * FAIL-OPEN WARNING: if this provider is not registered in bootstrap/providers.php,
     * RateLimiter::limiter() returns null and RateLimited::handle() silently passes every job
     * through. That is why QueueFairnessTest asserts an actual release rather than mere completion.
     */
    private function registerFairnessLimiter(): void
    {
        RateLimiter::for(
            (string) config('queue-fairness.limiter', 'tenant-queue'),
            static function (object $job): Limit {
                if (config('queue-fairness.enabled') !== true) {
                    return Limit::none();
                }

                // Only a TenantAwareJob has a tenant to be fair BETWEEN. Everything else falls through
                // unlimited — notably a MaintenanceJob, which is cross-tenant by design (§D3), is
                // paced by the scheduler rather than by tenant traffic, and is already single-flighted
                // by WithoutOverlapping. (MaintenanceJob does not extend TenantAwareJob, so it is
                // covered by this one check; a separate instanceof for it would be dead code.)
                if (! $job instanceof TenantAwareJob) {
                    return Limit::none();
                }

                $tenantId = $job->tenantId;

                // The queue actually reserved from, not $job->queue: the queue is chosen by the
                // #[Queue] attribute, so the property is null on a correctly-written job.
                $queue = $job->job?->getQueue() ?? QueueName::FALLBACK;

                $ceiling = self::ceilingFor($tenantId, $queue);

                return $ceiling === null
                    ? Limit::none()
                    : Limit::perMinute($ceiling)->by("tenant:{$tenantId}:queue:{$queue}");
            },
        );
    }

    /**
     * The tenant's ceiling for a queue: a plan-tier override if one resolves, otherwise the default.
     * null means unlimited.
     */
    private static function ceilingFor(string $tenantId, string $queue): ?int
    {
        $resolver = config('queue-fairness.tier_resolver');

        if (is_callable($resolver)) {
            $tier = $resolver($tenantId);

            if (is_string($tier)) {
                /** @var array<string, array<string, int|null>> $tiers */
                $tiers = config('queue-fairness.tiers', []);

                if (array_key_exists($tier, $tiers) && array_key_exists($queue, $tiers[$tier])) {
                    return $tiers[$tier][$queue];
                }
            }
        }

        /** @var array<string, int|null> $ceilings */
        $ceilings = config('queue-fairness.ceilings', []);

        return $ceilings[$queue] ?? null;
    }
}
