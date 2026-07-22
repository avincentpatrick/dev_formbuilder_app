<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueName;
use App\Exceptions\Jobs\InvalidJobPayloadException;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * The base class for ALL tenant-scoped queued work (ADR-0007 §D2). Generalizes the pattern
 * {@see ScanAttachmentJob} improvised as the codebase's only job, and makes tenant re-establishment
 * structural rather than a per-job convention — Phase 3 adds a dozen jobs, and one that forgets is a
 * tenant-boundary defect, not an ergonomics problem.
 *
 * The contract, in order:
 *   1. The payload carries a non-nullable uuid `$tenantId` (enforced three ways — see below).
 *   2. handle() asserts it is a well-formed uuid BEFORE any tenant-scoped query runs. This is the
 *      "fails fast (explicit exception)" ADR-0002 §D3 (`:125`) promised and §D6 (`:151`) required,
 *      and which did not exist until H2.
 *   3. Tenant lifecycle is resolved (§D13) against the RLS-exempt `tenants` table.
 *   4. Spatie's team id is set OUTSIDE the transaction; without it any job calling hasPermissionTo()
 *      fails closed — the HTTP middleware sets it (EstablishTenantDatabaseContext:52) and no job did.
 *   5. Work runs inside DB::transaction() → TenantContext::applyLocal(), because applyLocal is
 *      transaction-scoped and a worker has NO ambient request GUC.
 *
 * WHY SO MANY `final`s. A subclass that overrides handle() silently loses the assertion, the lifecycle
 * guard, applyLocal() and the transaction — and on a warm worker there is NO RUNTIME SYMPTOM, because
 * the previous job's process-lifetime static is still populated. That is the exact defect ADR-0007
 * §Context 6 documents. `final` converts it from an invisible security bug into a class-load fatal.
 * middleware()/failed()/retryUntil() are final for the same reason: CallQueuedHandler merges the
 * METHOD's return value, so a well-meaning override silently drops the §D9 fairness limiter.
 * Extend via the protected hooks instead: {@see jobMiddleware()}, {@see failureContext()},
 * {@see retryWindowHours()}.
 *
 * §D5 — PAYLOADS CARRY SCALAR IDS, NEVER ELOQUENT MODELS. The reason is mechanical: SerializesModels'
 * __unserialize runs inside CallQueuedHandler::call BEFORE handle(), and therefore before any of the
 * context re-establishment above, on a worker whose GUC is NULL. restoreModel() would find zero rows
 * under RLS and throw ModelNotFoundException as a PERMANENT failure.
 * {@see $deleteWhenMissingModels} is `final false` so nobody can "fix" that by flipping it — doing so
 * would convert a loud RLS-visibility signal into a job that silently deletes itself, i.e. silent
 * data loss in place of an error. scripts/job-payload-lint.php enforces the scalar-payload rule.
 */
abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The tenant this job acts for. Declared as a PHP 8.4 abstract property hook so that a subclass
     * which forgets it is a CLASS-LOAD FATAL rather than a runtime failure — the first of the three
     * independent gates ADR-0002 §D6 asked for (the others being handle()'s runtime uuid assertion
     * and scripts/job-payload-lint.php).
     *
     * Satisfy it with a promoted readonly constructor property:
     *     public function __construct(public readonly string $tenantId) {}
     */
    abstract public string $tenantId { get; }

    /**
     * §D5's named trap, sealed. See the class docblock.
     */
    final public bool $deleteWhenMissingModels = false;

    /**
     * §D7. NOTE THE ABSENCE OF $tries — that is deliberate and load-bearing.
     *
     * Fairness deferrals CONSUME ATTEMPTS: RateLimited calls $job->release(), and
     * DatabaseQueue::release() re-pushes the row preserving the already-incremented `attempts`. With
     * $tries = 3, three throttled deferrals would send the job to failed_jobs — exactly inverting
     * §D9's "defers excess work rather than rejecting it". $maxExceptions counts only genuine
     * exceptions and is blind to releases, so it is the only knob that expresses the intent.
     *
     * Once retryUntil() is non-null, maxTries is dead code in the worker — declaring $tries anyway
     * would be documentation that lies, the same failure mode §D5 guards against.
     */
    public int $maxExceptions = 3;

    /**
     * INVARIANT (§D7): must stay strictly below config('queue.connections.database.retry_after'),
     * currently 120. Otherwise the queue can hand the same job to a second worker while the first is
     * still running it.
     */
    public int $timeout = 60;

    /**
     * The work itself, already inside a transaction with RLS context and the Spatie team id
     * established. Everything a normal tenant-scoped service method may assume is true here.
     */
    abstract protected function handleForTenant(): void;

    final public function handle(): void
    {
        $this->assertTenantIdIsWellFormed();

        // `tenants` is RLS-exempt (it is the discriminator table), so this read is legal with no GUC
        // set — which is exactly the state the §D4 listener leaves the worker in.
        $tenant = Tenant::query()->whereKey($this->tenantId)->first();

        if ($tenant === null) {
            $this->discardForMissingTenant();

            return;
        }

        if (! $tenant->isActive()) {
            $this->deferForSuspendedTenant();

            return;
        }

        // OUTSIDE the transaction: Spatie's team id is process state, not database state, and the
        // permission cache it feeds is not transactional. The §D4 listener resets it on the way out.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantId);

        DB::transaction(function (): void {
            TenantContext::applyLocal($this->tenantId, null);

            $this->handleForTenant();
        });
    }

    /**
     * §D9. The fairness limiter is applied to EVERY tenant job — opting in per job would leave the
     * ceiling as advisory, and a single unthrottled job type is enough to starve the queue.
     *
     * @return array<int, object>
     */
    final public function middleware(): array
    {
        return [
            new RateLimited((string) config('queue-fairness.limiter', 'tenant-queue')),
            ...$this->jobMiddleware(),
        ];
    }

    /**
     * Additional middleware for a subclass. Overriding middleware() itself would drop the limiter.
     *
     * @return array<int, object>
     */
    protected function jobMiddleware(): array
    {
        return [];
    }

    /**
     * §D7 + §D9. Time-based expiry rather than an attempt count, because fairness deferrals consume
     * attempts (see $maxExceptions). CAVEAT: this is frozen at DISPATCH time and does not slide
     * across releases, so a job born into a backlog deeper than the window fails on its first pop.
     */
    final public function retryUntil(): Carbon
    {
        return now()->addHours($this->retryWindowHours());
    }

    protected function retryWindowHours(): int
    {
        return (int) config('queue-fairness.retry_window_hours', 6);
    }

    /**
     * §D7: an escalating ladder with jitter, satisfying technical-architecture.md:481's requirement
     * that retries are "never a naive immediate re-queue loop". Jitter spreads a thundering herd when
     * many tenants' jobs fail on the same downstream outage.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_map(
            static fn (int $seconds): int => $seconds + random_int(0, (int) round($seconds * 0.2)),
            [30, 120, 600],
        );
    }

    /**
     * §D12. Structured failure context. NEVER re-enters tenant context: by the time this runs the
     * database is frequently the thing that just failed, so re-establishing a GUC is both unreliable
     * and a way to turn one failure into two.
     *
     * The exception MESSAGE is deliberately omitted — this lands in logs alongside `failed_jobs`,
     * which is RLS-free, and a message can carry tenant data. The class name is enough to triage;
     * the full trace is already in failed_jobs.exception.
     */
    final public function failed(?Throwable $e): void
    {
        Log::error('Queued job failed.', [
            'job' => static::class,
            'tenant_id' => $this->tenantId,
            'queue' => $this->resolvedQueue(),
            'attempts' => $this->job?->attempts() ?? 0,
            'exception_class' => $e === null ? null : $e::class,
            ...$this->failureContext(),
        ]);
    }

    /**
     * Extra structured context for this job's failures — ids, never payloads or messages.
     *
     * @return array<string, scalar|null>
     */
    protected function failureContext(): array
    {
        return [];
    }

    /**
     * §D13: a job for a hard-deleted tenant is correctly a no-op. delete() rather than fail(), so
     * failed_jobs does not fill with noise for tenants that no longer exist.
     */
    private function discardForMissingTenant(): void
    {
        Log::info('Discarding queued job for a tenant that no longer exists.', [
            'job' => static::class,
            'tenant_id' => $this->tenantId,
        ]);

        $this->delete();
    }

    /**
     * §D13: a suspension is usually temporary, so release rather than fail — but it must not retry
     * forever. The bound is measured from the job's own dispatch timestamp rather than an attempt
     * count, because attempts are also consumed by fairness deferrals and would conflate "this
     * tenant is suspended" with "this tenant is busy".
     *
     * NOTE the explicit non-goal recorded in §D13: `tenants.status` is read by NO middleware, policy
     * or RLS predicate today, so this is queue-side handling only — it is not, and must not be
     * mistaken for, request-path suspension enforcement.
     */
    private function deferForSuspendedTenant(): void
    {
        if ($this->suspensionWindowExhausted()) {
            Log::info('Discarding queued job for a tenant suspended beyond the retry window.', [
                'job' => static::class,
                'tenant_id' => $this->tenantId,
            ]);

            $this->delete();

            return;
        }

        $this->release($this->backoff()[0]);
    }

    private function suspensionWindowExhausted(): bool
    {
        $payload = $this->job?->payload();
        $createdAt = is_array($payload) ? ($payload['createdAt'] ?? null) : null;

        if (! is_int($createdAt)) {
            return false; // no job instance (direct invocation) or an unexpected payload — never discard.
        }

        // 75% of the retry window: leaves headroom so a suspended job is discarded deliberately here
        // rather than expiring through retryUntil() and landing in failed_jobs as a "failure".
        $windowSeconds = (int) round($this->retryWindowHours() * 3600 * 0.75);

        return (now()->getTimestamp() - $createdAt) > $windowSeconds;
    }

    private function assertTenantIdIsWellFormed(): void
    {
        $tenantId = $this->tenantId;

        if (trim($tenantId) === '') {
            throw InvalidJobPayloadException::missingTenantId(static::class);
        }

        // The all-zeroes uuid is BelongsToTenant::NO_TENANT_SENTINEL — a job carrying it serialized an
        // ABSENT tenant, which is the bug rather than a valid target. Checked before the format test
        // so the error names the real cause. {@see BelongsToTenant}
        if ($tenantId === '00000000-0000-0000-0000-000000000000') {
            throw InvalidJobPayloadException::sentinelTenantId(static::class);
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId) !== 1) {
            throw InvalidJobPayloadException::malformedTenantId(static::class, $tenantId);
        }
    }

    /**
     * The queue this execution was actually reserved from. Read off the job instance, NOT $this->queue
     * — the queue is selected by the #[Queue] attribute (see App\Enums\QueueName for why a $queue
     * property is a fatal error), so the property is null on a correctly-written job.
     */
    private function resolvedQueue(): string
    {
        return $this->job?->getQueue() ?? QueueName::FALLBACK;
    }
}
