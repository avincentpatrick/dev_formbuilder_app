<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Enums\OcrScanStatus;
use App\Exceptions\Ocr\OcrException;
use App\Models\Form;
use App\Models\OcrScan;
use App\Services\Forms\CapabilityFlags;

/**
 * The scans page (M129 — single-form OCR groundwork 2): where a scan is uploaded, and where a scan that
 * finished reading while nobody watched can be found again.
 *
 * The page renders even when this form does not accept scans, because the route's gates are WHO and the
 * workspace (`can:create`, the module, the plan) — the form's own opt-in is the upload's refusal to make. So
 * the page says why uploading is off, in the upload's own words, rather than answering 403 to a reader who
 * may be the one able to turn it on.
 */
final class OcrScanListPresenter
{
    /** How many scans the page lists — the newest, through `ocr_scans_form_created_idx`. */
    private const LISTED = 25;

    /** @return array<string, mixed> */
    public function present(Form $form): array
    {
        $version = $form->currentPublishedVersion;
        $eligible = $version !== null && CapabilityFlags::isOcrCompatible($version);

        $scans = OcrScan::query()
            ->where('form_id', $form->id)
            ->with('uploader:id,name')
            ->latest()
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
