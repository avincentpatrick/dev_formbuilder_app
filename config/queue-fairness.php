<?php

declare(strict_types=1);

use App\Enums\QueueName;

/*
|--------------------------------------------------------------------------
| Per-tenant queue fairness (ADR-0007 §D9 — closes Risk R6)
|--------------------------------------------------------------------------
|
| A per-tenant ceiling on JOB EXECUTIONS STARTED, per queue, per minute — not HTTP requests, not
| rows. Enforced as JOB middleware (Illuminate\Queue\Middleware\RateLimited) rather than HTTP
| middleware, because the resource under contention is worker time: one request can enqueue a great
| deal of work, and a scheduled fan-out has no request at all.
|
| It DEFERS excess work rather than rejecting it (the middleware releases the job back with a delay),
| which is the correct semantics for a queue.
|
| >> EVERY NUMBER BELOW IS AN UNVALIDATED PLANNING ASSUMPTION. <<
|
| Nothing has ever consumed this queue, so no throughput data exists (ADR-0007 §D9 and the ADR's
| Method note say so explicitly). These values WILL be wrong in one direction or the other on first
| contact with real traffic. They live in config rather than code precisely so retuning is an
| operational change, not a deploy of code. ADR-0007 "When to Revisit" carries the trigger: retune
| against measured throughput on first real traffic and replace this banner with data.
|
| §D10: ceiling and lock state live in the cache/limiter store — NEVER a bespoke table. A
| `job_rate_*` table carrying a literal tenant_id would trip scripts/migration-lint.php, and both
| escapes are worse than the problem (RLS in the job-reservation hot path is a self-inflicted Risk
| R9; an EXEMPT_TABLES entry repeats the failed_jobs hazard of tenant ids in an RLS-free table).
|
*/

return [

    /*
    | Master switch. When false the limiter returns Limit::none() and no cache round-trip happens at
    | all — the escape hatch if fairness ever misbehaves in production, without a code deploy.
    */
    'enabled' => (bool) env('QUEUE_FAIRNESS_ENABLED', true),

    /*
    | The named limiter registered by App\Providers\QueueServiceProvider and referenced by
    | TenantAwareJob::middleware(). Note RateLimited stores its bucket under
    | md5($limiterName.$limit->key), so buckets are NOT greppable by tenant id in the cache table.
    */
    'limiter' => 'tenant-queue',

    /*
    | Job executions started per tenant, per queue, per minute. null = unlimited for that queue.
    |
    | The shape of the guesses: `submissions` is the highest because it is the user-facing ingest path
    | and a field team syncing an offline backlog is a legitimate burst; `ocr-processing` is the
    | lowest because it is the most expensive per job and the one with a paid external dependency.
    */
    'ceilings' => [
        QueueName::Submissions->value => (int) env('QUEUE_FAIRNESS_SUBMISSIONS', 120),
        QueueName::Webhooks->value => (int) env('QUEUE_FAIRNESS_WEBHOOKS', 60),

        // Forward-looking only. H3's queued transactional mail is delivered by framework queued
        // Notifications (Illuminate\Queue\...\SendQueuedNotifications), which are NOT TenantAwareJobs, so
        // App\Providers\QueueServiceProvider's limiter returns Limit::none() for them — this ceiling does
        // NOT throttle H3 mail. It governs a FUTURE TenantAwareJob on the `mail` queue (e.g. a bulk
        // emailed-digest fan-out); without the key such a job would be unlimited (ceilingFor => null).
        QueueName::Mail->value => (int) env('QUEUE_FAIRNESS_MAIL', 60),

        QueueName::Exports->value => (int) env('QUEUE_FAIRNESS_EXPORTS', 12),
        QueueName::OcrProcessing->value => (int) env('QUEUE_FAIRNESS_OCR', 30),
        QueueName::ScheduledMaintenance->value => (int) env('QUEUE_FAIRNESS_MAINTENANCE', 60),

        // A job that escaped scripts/job-payload-lint.php and carries no #[Queue] attribute. Kept
        // deliberately tight — landing here is a bug, and a tight ceiling makes it visible.
        QueueName::FALLBACK => 30,
    ],

    /*
    | Plan-tier overrides: ['tier-key' => ['queue-name' => ceiling]]. Empty until entitlements land in
    | H5. Deliberately NO Cashier dependency — neither laravel/cashier nor stripe/stripe-php is
    | installed and payments are deferred to Phase 4 (ADR-0007 §D9); this is the integration point the
    | ADR's "When to Revisit" nominates if payments return.
    |
    | @var array<string, array<string, int|null>>
    */
    'tiers' => [],

    /*
    | Resolves a tenant uuid to a key in 'tiers'. null = every tenant uses 'ceilings'.
    |
    | HARD CONSTRAINTS on any future implementation, because of WHERE this runs:
    |   1. It executes inside job middleware, on a worker, with NO TENANT CONTEXT established — the
    |      §D4 listener has already flushed, and TenantAwareJob has not yet opened its transaction. It
    |      may therefore read only RLS-EXEMPT tables (tenants, domains, and plans when it exists).
    |      A tenant-scoped query here returns zero rows and would silently yield the default ceiling.
    |   2. It runs on EVERY job execution. It must be cache-backed; a per-job database round-trip
    |      would put a query in front of every unit of work on the platform.
    |
    | @var callable(string): ?string|null
    */
    'tier_resolver' => null,

    /*
    | The §D7 retry window, in hours. TenantAwareJob uses retryUntil() rather than $tries because
    | fairness deferrals CONSUME ATTEMPTS: DatabaseQueue::release() preserves the already-incremented
    | attempts count, so a $tries value would convert "this tenant is being throttled" into
    | "this tenant's jobs permanently failed" — exactly inverting §D9.
    |
    | CAVEAT: retryUntil() is frozen at DISPATCH time and does NOT slide across releases, so a job
    | born into a backlog deeper than this window fails on its first pop. 6h is generous for a 60s
    | job; too small and a nightly fan-out across many tenants starts failing. This is a capacity
    | judgement with no measurement behind it.
    */
    'retry_window_hours' => (int) env('QUEUE_FAIRNESS_RETRY_WINDOW_HOURS', 6),

];
