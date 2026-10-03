<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one uploaded scan stands in the reading path (M128 — `docs/ocr-pipeline-design.md` §3, §6). The
 * `ocr_scans_status_check` CHECK is generated from {@see values()}, so the enum and the constraint cannot
 * drift.
 *
 *   - `queued`  — stored; the reading job has not started, or is waiting for a page's virus scan.
 *   - `reading` — at least one page has been sent to the provider and at least one has not.
 *   - `read`    — every page was read and matched to the printed form; `extraction` holds the result.
 *   - `failed`  — the provider refused or kept failing, or the scan cannot be matched to an OCR-eligible
 *     version. `error_code` and `error_message` say which and what to do. The page files are KEPT, so a
 *     failure never costs the user the scan (§6).
 *
 * `read` is not "correct". A read scan's answers are a staged proposal that a person reviews before
 * anything reaches the submission pipeline — groundwork 2's screen.
 */
enum OcrScanStatus: string
{
    case Queued = 'queued';
    case Reading = 'reading';
    case Read = 'read';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** No further reading happens from here without a new upload. */
    public function isTerminal(): bool
    {
        return $this === self::Read || $this === self::Failed;
    }
}
