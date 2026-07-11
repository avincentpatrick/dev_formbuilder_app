<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The channel a submission entered through (data-dictionary §7) — the six ingest channels named in
 * plan §2.2/§2.4, all funneled through the single `SubmissionPipeline`. Phase 1 lands `manual` and
 * `guest`; `ocr_single`/`ocr_linelist` (Phase 3), `offline_sync` (Phase 2), and `api_import` are the
 * later channels the same pipeline already accommodates by design.
 */
enum SubmissionSource: string
{
    case Manual = 'manual';
    case Guest = 'guest';
    case OcrSingle = 'ocr_single';
    case OcrLinelist = 'ocr_linelist';
    case OfflineSync = 'offline_sync';
    case ApiImport = 'api_import';

    /** Human label for the inbox source column/filter and the export metadata column (single source). */
    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual entry',
            self::Guest => 'Guest link',
            self::OcrSingle => 'OCR (single)',
            self::OcrLinelist => 'OCR (line list)',
            self::OfflineSync => 'Offline sync',
            self::ApiImport => 'API import',
        };
    }
}
