<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Enums\AttachmentKind;
use App\Enums\OcrScanStatus;
use App\Enums\SubmissionSource;
use App\Exceptions\Ocr\OcrException;
use App\Models\Attachment;
use App\Models\FormVersion;
use App\Models\OcrScan;
use App\Models\User;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Services\Submissions\SubmissionResult;
use Illuminate\Support\Facades\DB;

/**
 * Saves a reviewed scan as an ordinary submission (M129 — single-form OCR groundwork 2,
 * `docs/ocr-pipeline-design.md` §4 and §5).
 *
 * ── THE PIPELINE IS NEVER BYPASSED ──────────────────────────────────────────────────────────────────
 * §1's one architectural rule: OCR output is a proposal, and only a confirmed proposal enters
 * `SubmissionPipeline`, as any response does — full validation, relevance pruning, capacity, the audit row.
 * The answers handed in are the REVIEWER's, posted from the review page, never the extraction itself.
 *
 * ── THE SCAN'S ID IS THE SUBMISSION'S IDEMPOTENCY KEY ───────────────────────────────────────────────
 * The payload carries the scan id as `client_submission_uuid`, so a double-clicked Save, or a retry after the
 * link below failed, resolves to the SAME submission through the pipeline's Stage 2b rather than creating a
 * second one. ⚠️ This class never queries that column itself: `ClientUuidScopeTest` allows exactly one file
 * in the application to, and it is the resolver the pipeline already calls.
 *
 * ── THEN THE SCAN IS LINKED, IN ITS OWN TRANSACTION ─────────────────────────────────────────────────
 * The pipeline commits its own transaction and retries it on a deadlock, which cannot run inside an outer
 * one. So the link is a second step: lock the scan, record the submission, and move exactly this scan's page
 * files to the submission (§5 — `SubmissionFinalizer` moves only the files a media ANSWER names, and a scan
 * page is not one). If this step fails, the submission exists and a retry finds it through the key above.
 */
final class OcrScanConfirmation
{
    public function __construct(private readonly SubmissionPipeline $pipeline) {}

    /**
     * @param  array<string, mixed>  $answers  the reviewer's answers, exactly as the review page posted them
     *
     * @throws OcrException when the scan has nothing to save
     */
    public function confirm(FormVersion $current, OcrScan $scan, User $reviewer, array $answers): SubmissionResult
    {
        if ($scan->status !== OcrScanStatus::Read) {
            throw OcrException::notReviewable();
        }

        $result = $this->pipeline->submit(new SubmissionPayload(
            version: $current,
            answers: $answers,
            source: SubmissionSource::OcrSingle,
            respondentUserId: (string) $reviewer->id,
            clientSubmissionUuid: $scan->id,
        ));

        $this->link($scan->id, (string) $result->submission->id, (string) $reviewer->id);

        return $result;
    }

    private function link(string $scanId, string $submissionId, string $reviewerId): void
    {
        DB::transaction(static function () use ($scanId, $submissionId, $reviewerId): void {
            $scan = OcrScan::query()->whereKey($scanId)->lockForUpdate()->firstOrFail();
            if ($scan->submission_id !== null) {
                return; // a concurrent save linked it first, to the same submission
            }

            $pages = array_map(static fn (array $page): string => $page['attachment_id'], $scan->pages);

            Attachment::query()
                ->whereIn('id', $pages)
                ->where('kind', AttachmentKind::OcrSourceScan->value)
                ->where('attachable_type', 'ocr_scan')
                ->where('attachable_id', $scan->id)
                ->update(['attachable_type' => 'submission', 'attachable_id' => $submissionId]);

            $scan->forceFill([
                'submission_id' => $submissionId,
                'confirmed_at' => now(),
                'confirmed_by' => $reviewerId,
            ])->save();
        });
    }
}
