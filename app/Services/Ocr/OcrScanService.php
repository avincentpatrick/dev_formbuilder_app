<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Enums\OcrScanSource;
use App\Enums\OcrScanStatus;
use App\Enums\UsageMetric;
use App\Exceptions\Attachments\AttachmentException;
use App\Exceptions\Entitlements\QuotaExceededException;
use App\Exceptions\Ocr\OcrException;
use App\Exceptions\Submissions\FormNotAcceptingSubmissionException;
use App\Jobs\ReadOcrScanJob;
use App\Models\Form;
use App\Models\OcrScan;
use App\Models\User;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Entitlements\QuotaGuard;
use App\Services\Forms\CapabilityFlags;
use App\Services\Submissions\FormAcceptanceGuard;
use App\Support\Submissions\SubmissionReference;
use Illuminate\Database\UniqueConstraintViolationException;
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
 *
 * ── A FILLED PAPER COPY FROM THE PUBLIC LINK (M165, `R-7173c02c`, `D106`) ───────────────────────────
 * {@see storeFromGuest()} is the second door. No member stands behind it, so the FORM must say yes three times
 * — guest access, the Scanning switch and its own guest-upload switch — and the user's spend guard applies:
 * the route bounds one device per window and requires a solved spam check, and this class bounds the form per
 * day. The scan then joins the same list, the same reading job and the same mandatory review as a staff scan;
 * only `source` and the `reference` the guest was given tell them apart.
 */
final class OcrScanService
{
    /** Attempts at a reference no other scan of the workspace holds; a collision is ~1 in 10^12 per scan. */
    private const REFERENCE_ATTEMPTS = 3;

    public function __construct(
        private readonly AttachmentStorageService $storage,
        private readonly QuotaGuard $quota,
        private readonly FormAcceptanceGuard $acceptance,
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

        return $this->persist($form, (string) $uploader->id, $files, OcrScanSource::Staff);
    }

    /**
     * A filled paper copy from the form's public link (M165, `D106`). The route has verified the share token,
     * bounded the device and checked the spam-check solution; this checks the FORM and the day, then stores the
     * scan exactly as a staff upload is stored, with no uploader and a fresh reference for the guest to quote.
     *
     * Every refusal about the form's settings is the same neutral {@see OcrException::guestUploadOff()}, and so is
     * a full storage quota: a stranger is owed neither the form's configuration nor the workspace's plan.
     *
     * ⚠️ THE DAILY CAP TAKES NO LOCK, deliberately. A row lock on `forms` would be held across the file writes and
     * would queue behind the submit path's capacity lock; without one the cap can be passed by as many uploads as
     * arrive in the same instant, and each of those has already paid a spam check and a slot in its device window.
     *
     * @param  list<UploadedFile>  $files  the scan's pages, in order
     *
     * @throws OcrException when the form does not take a paper copy, its day is full, or the upload is refused
     * @throws FormNotAcceptingSubmissionException when the form is not open
     * @throws AttachmentException when a page's sniffed type or size is refused
     */
    public function storeFromGuest(Form $form, array $files): OcrScan
    {
        if (! $form->allowsGuestAccess() || $form->allow_ocr_single !== true || $form->allow_guest_ocr !== true) {
            throw OcrException::guestUploadOff();
        }

        $version = $form->currentPublishedVersion;
        if ($version === null || ! CapabilityFlags::isOcrCompatible($version)) {
            throw OcrException::guestUploadOff();
        }

        $this->acceptance->assertCanStart($form);

        // A rolling 24 hours rather than a calendar day: there is no workspace time zone (`R-9fb95b45`), and a UTC
        // day would roll over at eight in the morning in Manila.
        $today = OcrScan::query()
            ->where('form_id', $form->id)
            ->where('source', OcrScanSource::Guest->value)
            ->where('created_at', '>=', now()->subDay())
            ->count();
        if ($today >= $form->guest_ocr_daily_cap) {
            throw OcrException::guestDailyCapReached();
        }

        try {
            $this->assertScan($files);

            return $this->persist($form, null, $files, OcrScanSource::Guest);
        } catch (QuotaExceededException) {
            throw OcrException::guestUploadOff();
        }
    }

    /**
     * Store the pages, record the scan and queue its reading — every door's last step, once every check has passed.
     *
     * @param  list<UploadedFile>  $files
     */
    private function persist(Form $form, ?string $uploaderId, array $files, OcrScanSource $source): OcrScan
    {
        $tenantId = (string) $form->tenant_id;
        $scanId = Uuid::uuid7()->toString();

        $pages = [];
        foreach ($files as $file) {
            $attachment = $this->storage->storeOcrScanPage($file, $tenantId, $scanId, $uploaderId);
            $pages[] = ['attachment_id' => (string) $attachment->id, 'response_path' => null];
        }

        for ($attempt = 1; ; $attempt++) {
            // A guest is given a code to quote; a staff upload is not, because its uploader is already on the page.
            $reference = $source === OcrScanSource::Guest ? SubmissionReference::mint() : null;

            try {
                return DB::transaction(function () use ($scanId, $tenantId, $form, $uploaderId, $pages, $source, $reference): OcrScan {
                    $scan = new OcrScan([
                        'tenant_id' => $tenantId,
                        'form_id' => $form->id,
                        'uploaded_by' => $uploaderId,
                        'status' => OcrScanStatus::Queued,
                        'provider' => (string) config('ocr.provider', 'google_vision'),
                        'pages' => $pages,
                    ]);
                    $scan->id = $scanId;
                    $scan->source = $source;
                    $scan->reference = $reference;
                    $scan->save();

                    // Queued after the commit (`queue.after_commit`), so the job never looks for a row that is not
                    // there yet.
                    ReadOcrScanJob::dispatch($scanId, $tenantId);

                    return $scan;
                });
            } catch (UniqueConstraintViolationException $collision) {
                // Only a reference can collide — the id is a fresh uuid7 — and only a guest scan carries one.
                if ($reference === null || $attempt >= self::REFERENCE_ATTEMPTS) {
                    throw $collision;
                }
            }
        }
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
