<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who sent a scan in (M165, `R-7173c02c`, `D106`). The `ocr_scans_source_check` CHECK is generated from
 * {@see values()}, so the enum and the constraint cannot drift.
 *
 *   - `staff` — uploaded from the form's "Scan paper forms" page by someone who may enter responses for it.
 *   - `guest` — a filled paper copy uploaded from the form's public link. It waits in the same staff list,
 *     marked "From the public link", and becomes a response only when a staff reviewer saves it.
 */
enum OcrScanSource: string
{
    case Staff = 'staff';
    case Guest = 'guest';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
