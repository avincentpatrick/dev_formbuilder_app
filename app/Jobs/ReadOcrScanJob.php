<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueName;
use App\Models\OcrScan;
use App\Services\Ocr\OcrReadStep;
use App\Services\Ocr\OcrScanReader;
use Illuminate\Queue\Attributes\Queue;

/**
 * Reads one uploaded scan, one page per run (M128 — single-form OCR groundwork 1).
 *
 * All the decisions live in {@see OcrScanReader}; this class only turns its answer into a queue action:
 * wait (release with a growing delay), continue (queue the next run, which `queue.after_commit` holds
 * until this run's transaction commits), or stop. `ocr-processing` is the catalog queue both workers
 * already drain, below submissions and mail, so a burst of scans never delays a respondent.
 *
 * Constructed with scalars only (`scripts/job-payload-lint.php` R3); the reader is resolved from the
 * container through a property hook, as {@see ScanAttachmentJob} resolves its scanner.
 */
#[Queue(QueueName::OcrProcessing)]
final class ReadOcrScanJob extends TenantAwareJob
{
    public function __construct(
        public readonly string $scanId,
        public readonly string $tenantId,
    ) {}

    private OcrScanReader $reader {
        get => app(OcrScanReader::class);
    }

    protected function handleForTenant(): void
    {
        $scan = OcrScan::query()->whereKey($this->scanId)->first();

        if ($scan === null) {
            return; // deleted, or hidden by RLS — nothing to read
        }

        $step = $this->reader->step($scan);

        if ($step === OcrReadStep::Wait) {
            $this->release($this->reader->backoffFor($scan));
        } elseif ($step === OcrReadStep::Continue) {
            self::dispatch($this->scanId, $this->tenantId);
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function failureContext(): array
    {
        return ['ocr_scan_id' => $this->scanId];
    }
}
