<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Enums\OcrScanStatus;
use App\Enums\UsageMetric;
use App\Exceptions\Attachments\AttachmentException;
use App\Exceptions\Ocr\OcrException;
use App\Jobs\ReadOcrScanJob;
use App\Models\Form;
use App\Models\OcrScan;
use App\Models\User;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Entitlements\QuotaGuard;
use App\Services\Forms\CapabilityFlags;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Takes one scan in at the door (M128 — single-form OCR groundwork 1): checks that the form may be scanned,
 * stores the pages, records the scan and queues its reading.
 *
 * ── EVERY REFUSAL HAPPENS BEFORE THE FIRST BYTE IS STORED ───────────────────────────────────────────
 * A scan is several files, and the shared write path checks and stores ONE at a time. Run as-is, a refusal
 * on page three would leave pages one and two on disk with no scan to own them. So every page's type and
 * size, the page count, the scan's total and the storage quota for the WHOLE scan are all checked first,
 * and only then is anything written. The scan's id is minted before the pages, the `feedback_report`
 * precedent, so each page row names its owner from its first INSERT.
 *
 * ── WHO MAY SCAN THIS FORM ──────────────────────────────────────────────────────────────────────────
 * The route has already decided WHO may: `can:create` on a submission for this form, the manual-encoding
 * gate, because OCR is a way of entering responses. Then the workspace's module toggle and plan. What is
 * left is the FORM: its own opt-in (`forms.allow_ocr_single`) and whether its current version can be read
 * at all (`CapabilityFlags::isOcrCompatible()`, `docs/ocr-pipeline-design.md` §2) — never the cached
 * `capability_flags` column, which §2's as-built note warns is stale between publishes. The reading job
 * re-checks the version the page itself names, because old paper can name an older one.
 */
final class OcrScanService
{
    public function __construct(
        private readonly AttachmentStorageService $storage,
        private readonly QuotaGuard $quota,
    ) {}

    /**
     * @param  list<UploadedFile>  $files  the scan's pages, in order
     *
     * @throws OcrException when the form or the upload is refused
     * @throws AttachmentException when a page's sniffed type or size is refused
     */
    public function store(Form $form, User $uploader, array $files): OcrScan
    {
        if ($form->allow_ocr_single !== true) {
            throw OcrException::scanningOff();
        }

        $version = $form->currentPublishedVersion;
        if ($version === null || ! CapabilityFlags::isOcrCompatible($version)) {
            throw OcrException::formNotEligible();
        }

        $this->assertScan($files);

        $tenantId = (string) $form->tenant_id;
        $scanId = Uuid::uuid7()->toString();

        $pages = [];
        foreach ($files as $file) {
            $attachment = $this->storage->storeOcrScanPage($file, $tenantId, $scanId, (string) $uploader->id);
            $pages[] = ['attachment_id' => (string) $attachment->id, 'response_path' => null];
        }

        return DB::transaction(function () use ($scanId, $tenantId, $form, $uploader, $pages): OcrScan {
            $scan = new OcrScan([
                'tenant_id' => $tenantId,
                'form_id' => $form->id,
                'uploaded_by' => $uploader->id,
                'status' => OcrScanStatus::Queued,
                'provider' => (string) config('ocr.provider', 'google_vision'),
                'pages' => $pages,
            ]);
            $scan->id = $scanId;
            $scan->save();

            // Queued after the commit (`queue.after_commit`), so the job never looks for a row that is not
            // there yet.
            ReadOcrScanJob::dispatch($scanId, $tenantId);

            return $scan;
        });
    }

    /**
     * Every check that does not need the disk: page count, a PDF on its own, each page's sniffed type and
     * size, the scan's total, and the storage quota for all of it.
     *
     * @param  list<UploadedFile>  $files
     */
    private function assertScan(array $files): void
    {
        $maxPages = (int) config('ocr.upload.max_pages', 5);
        if (count($files) > $maxPages) {
            throw OcrException::tooManyPages($maxPages);
        }

        /** @var list<string> $accepted */
        $accepted = config('ocr.upload.accepted_types');
        $maxFile = (int) config('ocr.upload.max_bytes_per_file');

        $total = 0;
        $pdfs = 0;
        foreach ($files as $file) {
            $mime = $file->getMimeType() ?? 'application/octet-stream';
            if (! in_array($mime, $accepted, true)) {
                throw AttachmentException::mimeRejected($mime);
            }
            if ((int) $file->getSize() > $maxFile) {
                throw AttachmentException::tooLarge($maxFile);
            }
            $pdfs += $mime === 'application/pdf' ? 1 : 0;
            $total += (int) $file->getSize();
        }

        if ($pdfs > 0 && count($files) > 1) {
            throw OcrException::pdfNotAlone();
        }

        $maxScan = (int) config('ocr.upload.max_bytes_per_scan');
        if ($total > $maxScan) {
            throw OcrException::scanTooLarge($maxScan);
        }

        $this->quota->assertCanCreate(UsageMetric::StorageBytes, $total);
    }
}
