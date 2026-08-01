<?php

declare(strict_types=1);

namespace App\Jobs\Submissions;

use App\Enums\QueueName;
use App\Enums\SubmissionPdfOutcome;
use App\Exceptions\Attachments\AttachmentException;
use App\Exceptions\Entitlements\QuotaExceededException;
use App\Jobs\TenantAwareJob;
use App\Models\Attachment;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Submissions\SubmissionPdfReadyNotification;
use App\Services\Submissions\SubmissionPdfRenderer;
use App\Services\Submissions\SubmissionPdfStorage;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Renders one submission to PDF and stores it (Increment H17) — the FIRST consumer of
 * {@see QueueName::Exports}, which has existed with none since H2.
 *
 * ── Every terminal state emails the requester ───────────────────────────────────────────────────
 * A deterministic failure is CAUGHT, reported and returned cleanly rather than rethrown. Two
 * reasons, and neither is squeamishness about exceptions:
 *
 *   1. Retrying is pointless and harmful. `TenantAwareJob` sets `$maxExceptions = 3` and declares
 *      no `$tries` (fairness deferrals consume attempts, so `$tries` would invert §D9). A tenant
 *      over its storage quota will be over it three times, and each attempt re-renders the whole
 *      document — three times the work for the same refusal.
 *   2. Rethrowing tells the user NOTHING. There is no notification bell, no polling and no
 *      broadcast in this application, so a dead-lettered job is invisible: the user clicks
 *      "Generate PDF", gets a toast saying it is queued, and then silence forever. The email is
 *      the only channel, so the job's contract is "always report", not "always succeed".
 *
 * An UNEXPECTED throwable is different — it is reported to the user AND rethrown, so it reaches
 * `failed_jobs` and the base class's `failed()` hook with its `failureContext()`. A bug should be
 * loud in the logs even though the user has already been told something went wrong.
 *
 * ── Notes on the substrate ──────────────────────────────────────────────────────────────────────
 * The whole body runs inside `TenantAwareJob`'s transaction with RLS context established, which is
 * what makes the `attachments` strict-RLS `WITH CHECK` pass and `BelongsToTenant` fill `tenant_id`.
 * It also means the dompdf render happens with a transaction open; that is accepted rather than
 * ideal (the base class owns the transaction and exposes no seam), and `$timeout` is raised below
 * to bound it.
 *
 * Payload is scalar-only, so `scripts/job-payload-lint.php` R3 passes and no model is serialised
 * under a NULL GUC (ADR-0002 §D5).
 */
#[Queue(QueueName::Exports)]
final class GeneratePdfJob extends TenantAwareJob
{
    /**
     * Raised from the base class's 60.
     *
     * dompdf is pure PHP and a long submission with many repeat instances is genuinely slow; 60s
     * was chosen for jobs that mostly wait on I/O, and this one burns CPU. The INVARIANT the base
     * class states still holds and is the reason this is 110 rather than higher: it must stay
     * strictly below `config('queue.connections.database.retry_after')`, which is 120, or the
     * queue can hand the same job to a second worker while the first is still rendering.
     */
    public int $timeout = 110;

    public function __construct(
        public readonly string $submissionId,
        public readonly string $tenantId,
        public readonly string $requestedByUserId,
    ) {}

    protected function handleForTenant(): void
    {
        $submission = Submission::query()
            ->with(['form:id,title', 'formVersion:id,version_number', 'respondent:id,name,email', 'answers'])
            ->whereKey($this->submissionId)
            ->first();

        // Deleted between enqueue and execution, or hidden by RLS. Nothing to render and nobody to
        // apologise to — silence is correct here, unlike every other exit below.
        if ($submission === null) {
            return;
        }

        $formTitle = (string) (data_get($submission, 'form.title') ?? 'your form');

        try {
            $attachment = app(SubmissionPdfStorage::class)->store(
                $submission,
                app(SubmissionPdfRenderer::class)->render($submission),
            );

            $this->report($formTitle, SubmissionPdfOutcome::Ready, $attachment);
        } catch (QuotaExceededException $e) {
            // A limit, not a bug: log at warning and let the user act on it.
            Log::warning('Submission PDF refused by storage quota', $this->failureContext() + ['reason' => $e->getMessage()]);
            $this->report($formTitle, SubmissionPdfOutcome::QuotaExceeded);
        } catch (AttachmentException $e) {
            // Today this is only the malware verdict, which means our own render was flagged —
            // loud in the log, gentle to the user, and never retried.
            Log::error('Submission PDF rejected before storage', $this->failureContext() + ['reason' => $e->getMessage()]);
            $this->report($formTitle, SubmissionPdfOutcome::Failed);
        } catch (Throwable $e) {
            // Unknown fault: tell the user, then RETHROW so it lands in failed_jobs rather than
            // being swallowed into a green queue run.
            $this->report($formTitle, SubmissionPdfOutcome::Failed);

            throw $e;
        }
    }

    /**
     * Email the requesting user.
     *
     * The five-line idiom used verbatim by `DeliverWebhookJob`, `ReconcileTenantUsageJob` and
     * `DeliverConnectorMessageJob`: read the address off the row and send to an ON-DEMAND
     * notifiable, never `$user->notify()`, because `SendQueuedNotifications` would serialise a
     * `User` under a NULL GUC and RLS would fail it closed.
     *
     * Failing to notify must never fail the job — the document is already stored, and a bounced
     * mail server is not a reason to re-render and re-charge the quota.
     */
    private function report(string $formTitle, SubmissionPdfOutcome $outcome, ?Attachment $attachment = null): void
    {
        try {
            $email = User::query()->whereKey($this->requestedByUserId)->value('email');

            if (! is_string($email) || $email === '') {
                return;
            }

            Notification::route('mail', $email)->notify(new SubmissionPdfReadyNotification(
                $formTitle,
                $this->submissionId,
                $outcome,
                $attachment === null ? null : $this->downloadUrl($attachment),
            ));
        } catch (Throwable $e) {
            Log::warning('Submission PDF notification failed', $this->failureContext() + ['reason' => $e->getMessage()]);
        }
    }

    /**
     * The tenant-host download link.
     *
     * Points at the EXISTING session-gated `GET /attachments/{attachment}` route, so H17 adds no
     * new authorisation surface: the recipient is a staff user of this tenant, the route already
     * carries `can:view,attachment`, and following the link while signed out lands on the normal
     * login redirect. That is the whole reason the audience is staff — a respondent-facing link
     * would need a public signed route, which does not exist anywhere in this application.
     */
    private function downloadUrl(Attachment $attachment): ?string
    {
        $tenant = Tenant::query()->whereKey($this->tenantId)->first();

        return $tenant === null ? null : TenantUrl::to($tenant, 'attachments/'.$attachment->getKey());
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function failureContext(): array
    {
        return [
            'submission_id' => $this->submissionId,
            'requested_by' => $this->requestedByUserId,
        ];
    }
}
