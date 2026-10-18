<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Enums\FieldType;
use App\Enums\PdfFieldRole;

/**
 * "Show to respondents: Always · Never · Only when…" (M177, `R-f44e9d00`) — `config.visibility`, read in one place.
 *
 * `hidden` is Never: nobody filling the form sees the question, and it raises no error nobody could repair (the Hidden
 * type's rule, H7). `shown` is Always, which only a calculation needs, since it is Never by default: its value is
 * shown, read-only. Absent — every question and every version before M177 — keeps the type's own rule, so a published
 * version's snapshot and checksum are untouched. "Only when…" is the existing `relevant_expression`.
 *
 * ⛔ TWIN OF `fieldRendersNothing()` IN `resources/public-runtime/engine/field-roles.ts`, pinned by
 * `tests/golden/validation/visibility.json` (both engines) and `tests/fixtures/step-projection.json` (both
 * projections). {@see PdfFieldRole} stays the per-TYPE default — `PdfFieldRoleTest` parses the TypeScript set against
 * it — and this is the per-FIELD rule beside it.
 */
final class FieldVisibility
{
    public const HIDDEN = 'hidden';

    public const SHOWN = 'shown';

    /** The stored value, or null — anything but the two values reads as the type's own rule. */
    public static function of(mixed $config): ?string
    {
        $value = is_array($config) ? ($config['visibility'] ?? null) : null;

        return $value === self::HIDDEN || $value === self::SHOWN ? $value : null;
    }

    /** Whether this field puts nothing in front of a respondent. */
    public static function rendersNothing(FieldType $type, mixed $config): bool
    {
        if ($type === FieldType::Hidden || $type === FieldType::PageBreak) {
            return true;
        }

        $visibility = self::of($config);
        if ($visibility === self::HIDDEN) {
            return true;
        }
        if ($type === FieldType::Calculated) {
            return $visibility !== self::SHOWN;
        }

        return PdfFieldRole::for($type) === PdfFieldRole::Omitted;
    }

    /** The PDF's role for this field: an Always calculation is an answer the respondent saw; a Never question is not. */
    public static function pdfRole(FieldType $type, mixed $config): PdfFieldRole
    {
        if (self::rendersNothing($type, $config)) {
            return PdfFieldRole::Omitted;
        }

        return $type === FieldType::Calculated ? PdfFieldRole::Answer : PdfFieldRole::for($type);
    }
}
