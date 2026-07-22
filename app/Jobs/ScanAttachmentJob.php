<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueName;
use App\Enums\ScanStatus;
use App\Models\Attachment;
use Illuminate\Queue\Attributes\Queue;

/**
 * Virus-scans a freshly uploaded {@see Attachment} (Increment G6) — the project's first queued job,
 * and as of H2 the first to run on the {@see TenantAwareJob} substrate (ADR-0007 §D2). Phase-1
 * has no scanner wired ({@see config('attachments.scanner_enabled')} = false), so it transitions
 * `pending → skipped` (servable, but explicitly "not scanned"); a real ClamAV integration later flips
 * the config and transitions `pending → clean|infected`. Idempotent: it only acts on a still-`pending`
 * row.
 *
 * The transaction / RLS re-establishment this class used to improvise inline now lives in the base
 * class, which is the point of ADR-0007 §D2 — the rule its old docblock stated ("a queue worker has no
 * ambient request GUC, so the update must run inside DB::transaction or RLS would scope it to zero
 * rows") is now structural rather than a per-job convention that the next dozen jobs could forget.
 *
 * `$tenantId` satisfies the base class's abstract property hook; the constructor signature is
 * UNCHANGED from G6 because it is constructed positionally elsewhere.
 */
#[Queue(QueueName::Submissions)]
final class ScanAttachmentJob extends TenantAwareJob
{
    public function __construct(
        public readonly string $attachmentId,
        public readonly string $tenantId,
    ) {}

    protected function handleForTenant(): void
    {
        $attachment = Attachment::query()->whereKey($this->attachmentId)->first();

        if ($attachment === null || $attachment->virus_scan_status !== ScanStatus::Pending) {
            return; // already resolved, or hidden by RLS — nothing to do
        }

        // No scanner configured (Phase-1) ⇒ mark skipped. Real ClamAV later sets clean|infected here.
        $status = config('attachments.scanner_enabled') === true
            ? $this->scan($attachment)
            : ScanStatus::Skipped;

        $attachment->update(['virus_scan_status' => $status]);
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function failureContext(): array
    {
        return ['attachment_id' => $this->attachmentId];
    }

    /**
     * Placeholder for the real scanner integration. Until ClamAV is wired, `scanner_enabled` is false
     * and this is never reached; when enabled it must return {@see ScanStatus::Clean} or
     * {@see ScanStatus::Infected}.
     */
    private function scan(Attachment $attachment): ScanStatus
    {
        return ScanStatus::Clean;
    }
}
