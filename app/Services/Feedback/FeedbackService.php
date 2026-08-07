<?php

declare(strict_types=1);

namespace App\Services\Feedback;

use App\Enums\FeedbackStatus;
use App\Models\FeedbackReport;
use App\Models\User;
use App\Services\Admin\SuperAdminService;
use App\Services\Attachments\AttachmentStorageService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * The tenant-side feedback write path (PRD Feature #11, Increment I7a) — the service layer C3 never had.
 * Until now the only writer in the product was a bare `FeedbackReport::create()` inside a controller,
 * which was defensible while a report was three scalars and became indefensible the moment it acquired a
 * stored object that has to be created in the same breath.
 *
 * **This service owns SUBMIT only.** The cross-tenant console reads (`listFeedback`) and the status
 * transitions (`transitionFeedback`) live on {@see SuperAdminService}, because RBAC §9 requires every
 * cross-tenant platform operation to route through that one narrow, named service rather than through a
 * second one that also knows how to elevate. Nothing here is ever reachable from the central host.
 *
 * ── CREATION IS DELIBERATELY NOT AUDITED, AND THAT IS THE SPEC'S ANSWER, NOT A JUDGEMENT CALL ────────────
 * `docs/audit-compliance-logging-spec.md` §1 registers exactly one event for this table: `updated`
 * ("status transitions by platform support"), for support-team accountability. Submitting feedback is a
 * user telling us something about our own product — it is not an act against tenant data, it has no
 * before-state, and the row IS its own record. The transitions are audited (see
 * {@see SuperAdminService::transitionFeedback()}) precisely because that is where someone acts ON a
 * tenant's report. The I3 "notifications are a derivative of an already-audited act" argument does not
 * transfer in either direction — this is simply what the spec already said.
 */
final class FeedbackService
{
    public function __construct(private readonly AttachmentStorageService $attachments) {}

    /**
     * Persist one feedback report and, if the reporter attached one, its screenshot.
     *
     * ── WHY THE UUID IS MINTED HERE RATHER THAN LEFT TO THE MODEL ────────────────────────────────────────
     * `attachments.attachable_type`/`attachable_id` are NOT NULL, so the screenshot needs its owner's id
     * before the owner row exists (the FK runs the other way — `feedback_reports.screenshot_attachment_id`).
     * Minting the report's uuid7 first and handing it to
     * {@see AttachmentStorageService::storeFeedbackScreenshot()} makes both directions agree from the first
     * INSERT, with no re-point step. There is no DB-level FK on the morph columns, so the attachment row
     * naming a report that is one statement away from existing is legal, and the transaction closes the
     * gap. **The id must be written with `forceFill`, not `create()`:** `id` is not in `$fillable`, so
     * `create(['id' => …])` would silently drop it and `HasUuidv7` would mint a DIFFERENT one — leaving the
     * attachment pointing at a report that never existed, with no error anywhere.
     *
     * Honest caveat: a rollback after the object is written leaves the stored file orphaned on disk (its
     * row is gone, so nothing serves it). That is the pre-existing behaviour of every upload path here and
     * is a storage-sweep concern, not a correctness one.
     *
     * @param  array{route: string, remarks: string, browser_info?: array<string, mixed>|null}  $validated
     */
    public function submit(array $validated, ?UploadedFile $screenshot, User $user): FeedbackReport
    {
        return DB::transaction(function () use ($validated, $screenshot, $user): FeedbackReport {
            $reportId = Uuid::uuid7()->toString();
            $tenantId = (string) TenantContext::currentTenantId();

            $attachment = $screenshot === null
                ? null
                : $this->attachments->storeFeedbackScreenshot($screenshot, $tenantId, $reportId, (string) $user->getKey());

            $report = new FeedbackReport;
            $report->forceFill([
                'id' => $reportId,
                'user_id' => $user->getKey(),
                'route' => $validated['route'],
                'remarks' => $validated['remarks'],
                'browser_info' => $validated['browser_info'] ?? [],
                'screenshot_attachment_id' => $attachment?->getKey(),
                // Written explicitly rather than left to the column default: `status` carries no CHECK
                // constraint, so the enum is the only guard there is (see FeedbackStatus's docblock).
                'status' => FeedbackStatus::New,
            ])->save();

            // submitted_at defaults at the DB (useCurrent) and is therefore not in the in-memory model.
            $report->refresh();

            return $report;
        });
    }
}
