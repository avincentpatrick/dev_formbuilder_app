<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Enums\OcrScanSource;
use App\Enums\OcrScanStatus;
use App\Exceptions\Ocr\OcrException;
use App\Models\Form;
use App\Models\OcrScan;
use App\Services\Forms\CapabilityFlags;
use App\Support\Submissions\SubmissionReference;

/**
 * The scans page (M129 — single-form OCR groundwork 2): where a scan is uploaded, and where a scan that
 * finished reading while nobody watched can be found again.
 *
 * The page renders even when this form does not accept scans, because the route's gates are WHO and the
 * workspace (`can:create`, the module, the plan) — the form's own opt-in is the upload's refusal to make. So
 * the page says why uploading is off, in the upload's own words, rather than answering 403 to a reader who
 * may be the one able to turn it on.
 *
 * A filled paper copy from the public link (M165, `D106`) is listed here like any scan, marked "From the public
 * link" with the reference its sender was given, and the page can look a scan up by that reference — the one
 * thing a guest can quote. ⚠️ Only here, behind this page's gates: a reference is a display handle, never a key a
 * route resolves on its own (`SubmissionReferenceDisclosureTest`'s rule for a response's).
 */
final class OcrScanListPresenter
{
    /** How many scans the page lists — the newest, through `ocr_scans_form_created_idx`. */
    private const LISTED = 25;

    /**
     * @param  string|null  $reference  a reference typed into the page's search, as typed; null or blank lists the newest
     * @return array<string, mixed>
     */
    public function present(Form $form, ?string $reference = null): array
    {
        $version = $form->currentPublishedVersion;
        $eligible = $version !== null && CapabilityFlags::isOcrCompatible($version);

        $searched = $reference !== null && trim($reference) !== '';
        $code = $searched ? SubmissionReference::normalize($reference) : null;

        // A search for something that cannot be a reference finds nothing, rather than quietly listing every scan.
        $scans = $searched && $code === null
            ? collect()
            : OcrScan::query()
                ->where('form_id', $form->id)
                ->when($code !== null, fn ($query) => $query->where('ocr_scans.reference', $code))
                ->with('uploader:id,name')
                ->latest()
                // `created_at` is kept to the second, so two scans sent in one second tied and came back in either
                // order (measured on CI, M165). The id is a uuid7, minted in time order, so it settles the tie.
                ->orderByDesc('id')
                ->limit(self::LISTED)
                ->get();

        return [
            'form' => ['id' => $form->id, 'title' => $form->title],
            'accepts_scans' => $form->allow_ocr_single === true,
            'refusal' => match (true) {
                $form->allow_ocr_single !== true => OcrException::scanningOff()->getMessage(),
                ! $eligible => OcrException::formNotEligible()->getMessage(),
                default => null,
            },
            'upload' => [
                'url' => route('forms.ocr.scans.store', $form, false),
                'max_pages' => (int) config('ocr.upload.max_pages', 5),
                'accepted_types' => array_values((array) config('ocr.upload.accepted_types', [])),
                'max_bytes_per_file' => (int) config('ocr.upload.max_bytes_per_file'),
                'max_bytes_per_scan' => (int) config('ocr.upload.max_bytes_per_scan'),
            ],
            'scans' => $scans->map(fn (OcrScan $scan): array => $this->row($form, $scan))->values()->all(),
            'search' => ['reference' => $searched ? trim((string) $reference) : null],
        ];
    }

    /** @return array<string, mixed> */
    private function row(Form $form, OcrScan $scan): array
    {
        $saved = $scan->isConfirmed();

        return [
            'id' => $scan->id,
            'status' => $saved ? 'saved' : $scan->status->value,
            'status_label' => $saved ? 'Saved as a response' : self::label($scan->status),
            'pages' => count($scan->pages),
            'uploaded_by' => $scan->uploader?->name,
            'from_public_link' => $scan->source === OcrScanSource::Guest,
            'reference' => $scan->reference === null ? null : SubmissionReference::format($scan->reference),
            'created_at' => $scan->created_at->toIso8601String(),
            'review_url' => $saved ? null : route('forms.ocr.scans.review', ['form' => $form, 'scan' => $scan->id], false),
            'submission_url' => $saved ? '/submissions/'.$scan->submission_id : null,
            'error_message' => $scan->status === OcrScanStatus::Failed ? $scan->error_message : null,
        ];
    }

    /** The status in the reviewer's words. The page receives the words; it holds no list of statuses. */
    public static function label(OcrScanStatus $status): string
    {
        return match ($status) {
            OcrScanStatus::Queued => 'Waiting to be read',
            OcrScanStatus::Reading => 'Being read',
            OcrScanStatus::Read => 'Ready to review',
            OcrScanStatus::Failed => 'Could not be read',
        };
    }
}
