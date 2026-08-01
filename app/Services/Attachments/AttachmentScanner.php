<?php

declare(strict_types=1);

namespace App\Services\Attachments;

use App\Enums\ScanStatus;
use App\Jobs\ScanAttachmentJob;
use App\Jobs\Submissions\GeneratePdfJob;
use App\Services\Webhooks\WebhookPayloadArchive;

/**
 * The malware-scan seam (extracted in Increment H17).
 *
 * Before H17 the scan was a `private` method on a `final` {@see ScanAttachmentJob} whose
 * `handle()` is also `final` — reachable from nothing else, by construction. H17 needs the same
 * decision on bytes that exist only in memory, so the rule moved here and both callers share it:
 *
 *   - {@see ScanAttachmentJob} — an uploaded file, ASYNCHRONOUSLY: the row is written `pending`,
 *     the job resolves it later. Behaviour is byte-identical to G6; its existing tests pin that.
 *   - {@see GeneratePdfJob} — a generated PDF, SYNCHRONOUSLY, before anything is written. That is
 *     the H-map's "in-worker virus-scan gate", and the ordering is inverted on purpose (below).
 *
 * ── Why the PDF gate is synchronous, and why it may NOT take the Skipped shortcut ───────────────
 * {@see WebhookPayloadArchive} — the only other system-generated artifact —
 * writes `ScanStatus::Skipped` and never scans, justified in its own code as "system-generated
 * JSON, never scanned and NEVER SERVED TO A BROWSER".
 *
 * A submission PDF is served to a browser. So the justification does not carry, and H17 does not
 * inherit the shortcut. It scans the bytes and refuses to write anything that comes back Infected,
 * which is strictly stronger than the upload path: an upload is stored first and quarantined by a
 * read-time 409 (`AttachmentController` checks `ScanStatus::servable()`), whereas an infected PDF
 * never reaches disk at all.
 *
 * ── Phase-1 status: still a stub, and deliberately still a stub ─────────────────────────────────
 * `config('attachments.scanner_enabled')` is false and no ClamAV is wired, so {@see scanBytes()}
 * returns {@see ScanStatus::Skipped}. H17 does NOT wire a real scanner — that is its own increment
 * with its own deployment surface (a daemon, a socket, a signature-update schedule). What H17
 * changes is that there is now ONE place to wire it, reached by both channels, instead of a
 * private method one of them could not call.
 */
class AttachmentScanner
{
    /**
     * Scan raw bytes that may never have touched a disk.
     *
     * Takes the content rather than a path or a model precisely so a caller can gate BEFORE
     * writing. `$filename` is advisory context for a future engine (some scanners use the
     * extension to pick a heuristic) and is deliberately not trusted for anything else.
     */
    public function scanBytes(string $contents, string $filename): ScanStatus
    {
        if (! $this->enabled()) {
            return ScanStatus::Skipped;
        }

        return $this->inspect($contents, $filename);
    }

    /** Whether a real engine is configured. Extracted so a test double can override just this. */
    protected function enabled(): bool
    {
        return config('attachments.scanner_enabled') === true;
    }

    /**
     * The real scanner integration point. Until ClamAV is wired, `enabled()` is false and this is
     * never reached; when enabled it must return {@see ScanStatus::Clean} or
     * {@see ScanStatus::Infected} and must never return {@see ScanStatus::Pending} — a caller
     * treats anything that is not Clean-or-Skipped as a refusal.
     */
    protected function inspect(string $contents, string $filename): ScanStatus
    {
        return ScanStatus::Clean;
    }
}
