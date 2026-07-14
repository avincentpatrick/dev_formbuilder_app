<?php

declare(strict_types=1);

namespace App\Enums;

use App\Jobs\ScanAttachmentJob;
use App\Models\Attachment;

/**
 * The virus-scan state of an {@see Attachment} (data-dictionary §10 `ScanStatus`). A file is
 * NEVER served to another user until it is {@see self::servable()} — the serving gate the threat model
 * (§6) relies on. New rows default to `pending`; a real scanner (ClamAV, deferred) later transitions
 * `pending → clean|infected`. In Phase-1 there is no scanner configured, so {@see ScanAttachmentJob}
 * transitions `pending → skipped` — servable, but explicitly marked "not scanned" rather than "clean".
 */
enum ScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case Skipped = 'skipped';

    /**
     * Whether the file may be served to a user. `clean` (scanned + safe) and `skipped` (no scanner
     * available, so trusted-by-policy in Phase-1) are servable; `pending` (scan not finished) and
     * `infected` (quarantined) are not.
     */
    public function servable(): bool
    {
        return $this === self::Clean || $this === self::Skipped;
    }
}
