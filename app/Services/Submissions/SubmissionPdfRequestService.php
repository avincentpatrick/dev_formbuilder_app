<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\AuditEvent;
use App\Enums\UsageMetric;
use App\Exceptions\Entitlements\QuotaExceededException;
use App\Jobs\Submissions\GeneratePdfJob;
use App\Models\Submission;
use App\Models\User;
use App\Services\Entitlements\QuotaGuard;
use App\Services\Entitlements\UsageMeter;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;

/**
 * Everything that must happen IN-REQUEST when a staff user asks for a submission PDF
 * (Increment H17), before {@see GeneratePdfJob} takes over on a worker.
 *
 * Each of the three steps is here rather than in the job for a specific reason:
 *
 *  1. QUOTA PRE-FLIGHT. `QuotaExceededException` is rendered as a 402 / error toast by
 *     `bootstrap/app.php`, which needs a request. Thrown on a worker it is just a job failure the
 *     user never sees. Asking for one byte answers the only question decidable before the render:
 *     "is this tenant already at its ceiling?" — the common case. The job re-checks with the real
 *     byte count, which is the only place the exact size is knowable.
 *  2. METER. `UsageMetric::ExportsCount` is metered, never enforced: it is `Unclassified`, and all
 *     three `QuotaGuard` methods throw `LogicException` on a non-`HardBlock` metric. Reclassifying
 *     it would flip `isGauge()` and break `EnforcementModeTest`, so H17 does what
 *     `SubmissionExporter` does — meters the REQUEST, not the completion. A refused or failed
 *     render still counts, exactly as a streamed export that the user cancels does.
 *  3. AUDIT. `AuditLogger` hard-codes `is_system_action = false` and resolves the actor from
 *     `Auth::id()`, so a row written from a worker is malformed — H12a met this and chose to write
 *     no audit at all. At enqueue the actor is live, so the row is correct. This is the FIRST call
 *     site of `AuditEvent::Exported`, which has been in the enum (and in the `audits_event_check`
 *     constraint) since H4 with nothing writing it.
 *
 * No `DomainEventType` case is added. That enum doubles as the webhook and connector SUBSCRIPTION
 * vocabulary, so a new case silently widens both products' event catalogues and both UIs' option
 * lists — and it is CHECK-constrained, so it is a migration too. A PDF request is not a domain
 * event anyone has asked to subscribe to.
 */
final class SubmissionPdfRequestService
{
    public function __construct(
        private readonly QuotaGuard $quota,
        private readonly UsageMeter $meter,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Pre-flight, record, and dispatch.
     *
     * @throws QuotaExceededException when the tenant is already at its storage ceiling
     */
    public function queue(Submission $submission, User $user): void
    {
        $this->quota->assertCanCreate(UsageMetric::StorageBytes);

        $this->meter->increment(UsageMetric::ExportsCount);

        $this->audit->record(
            AuditEvent::Exported,
            'submission',
            (string) $submission->getKey(),
            null,
            ['format' => 'pdf'],
            (string) $user->getKey(),
        );

        GeneratePdfJob::dispatch(
            (string) $submission->getKey(),
            (string) TenantContext::currentTenantId(),
            (string) $user->getKey(),
        );
    }
}
