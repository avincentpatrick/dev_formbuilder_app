<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\AttachmentKind;
use App\Models\Attachment;
use App\Models\OcrScan;
use App\Models\Submission;

/**
 * The scanned pages a response was saved from (M153, `R-1e00f872`) — empty for every response that was not a
 * scan. Saving a scan moves its page files to the response (`OcrScanConfirmation::link()`), so they are the
 * response's own; this is what puts them in front of the person checking its answers against the paper.
 *
 * Two pages read it: the response page ({@see SubmissionInboxPresenter::detail()}) and, since M154
 * (`R-1585698b`), the page that corrects its answers (`SubmissionEditController::edit()`) — a reviewer who spots a
 * misread answer keeps the paper beside the form while fixing it. It moved here from the inbox presenter word for
 * word so the two cannot drift.
 *
 * ⚠️ THE ORDER COMES FROM THE SCAN, NOT FROM THE FILES. `attachments` has no sequence column; the scan's
 * `pages` is the only ordered record, so the pages are numbered from it exactly as the review screen numbers
 * them (`OcrScanReviewPresenter::pages()`) and as a review note's "(page N)" counts them.
 *
 * ⚠️ THE URL IS `attachments.show`, NEVER THE REVIEW SCREEN'S `forms.ocr.scans.page`. That route is gated on
 * creating a response and on the OCR module and plan feature, so a viewer or a reviewer who may open the response
 * would get 403 there, and switching scanning off would hide every saved scan's pages.
 * `AttachmentPolicy` reads a scan page owned by a submission through `SubmissionPolicy::view()` — the response
 * page's own gate — so the files are listed only while the response owns them: a file it does not own is never
 * offered as a link the route would refuse.
 *
 * The `form_id` term is for the index: `ocr_scans.submission_id` has none, `(tenant_id, form_id, created_at)` does.
 */
final class ResponseScanPages
{
    /** @return list<array{number: int, url: string, mime: string, servable: bool}> */
    public static function for(Submission $submission): array
    {
        $scan = OcrScan::query()
            ->where('form_id', $submission->form_id)
            ->where('submission_id', $submission->getKey())
            ->first();

        if ($scan === null) {
            return [];
        }

        $ids = array_map(static fn (array $page): string => $page['attachment_id'], $scan->pages);
        $files = Attachment::query()
            ->whereIn('id', $ids)
            ->where('kind', AttachmentKind::OcrSourceScan)
            ->where('attachable_type', 'submission')
            ->where('attachable_id', $submission->getKey())
            ->get()
            ->keyBy('id');

        $pages = [];
        foreach ($ids as $index => $id) {
            $file = $files->get($id);
            if (! $file instanceof Attachment) {
                continue;
            }
            $pages[] = [
                'number' => $index + 1,
                'url' => route('attachments.show', ['attachment' => $file->getKey()], false),
                'mime' => $file->mime_type ?? 'application/octet-stream',
                'servable' => $file->virus_scan_status->servable(),
            ];
        }

        return $pages;
    }
}
