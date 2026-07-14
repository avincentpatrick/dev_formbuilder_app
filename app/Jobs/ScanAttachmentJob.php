<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Models\Attachment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Virus-scans a freshly uploaded {@see Attachment} (Increment G6) — the project's first queued job. Phase-1
 * has no scanner wired ({@see config('attachments.scanner_enabled')} = false), so it transitions `pending →
 * skipped` (servable, but explicitly "not scanned"); a real ClamAV integration later flips the config and
 * transitions `pending → clean|infected`. Idempotent: it only acts on a still-`pending` row.
 *
 * The job carries its own `tenantId` (ADR-0002 §D3 — every job payload is tenant-tagged) and re-establishes
 * the RLS context inside a transaction: {@see TenantContext::applyLocal()} is transaction-scoped, and a
 * queue worker has no ambient request GUC, so the update must run inside `DB::transaction` or RLS would
 * scope it to zero rows.
 */
final class ScanAttachmentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $attachmentId,
        public readonly string $tenantId,
    ) {}

    public function handle(): void
    {
        DB::transaction(function (): void {
            TenantContext::applyLocal($this->tenantId, null);

            $attachment = Attachment::query()->whereKey($this->attachmentId)->first();

            if ($attachment === null || $attachment->virus_scan_status !== ScanStatus::Pending) {
                return; // already resolved, or hidden by RLS — nothing to do
            }

            // No scanner configured (Phase-1) ⇒ mark skipped. Real ClamAV later sets clean|infected here.
            $status = config('attachments.scanner_enabled') === true
                ? $this->scan($attachment)
                : ScanStatus::Skipped;

            $attachment->update(['virus_scan_status' => $status]);
        });
    }

    /**
     * Placeholder for the real scanner integration. Until ClamAV is wired, `scanner_enabled` is false and
     * this is never reached; when enabled it must return {@see ScanStatus::Clean} or {@see ScanStatus::Infected}.
     */
    private function scan(Attachment $attachment): ScanStatus
    {
        return ScanStatus::Clean;
    }
}
