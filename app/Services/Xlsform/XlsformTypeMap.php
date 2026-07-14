<?php

declare(strict_types=1);

namespace App\Services\Xlsform;

use App\Enums\FieldType;
use App\Services\Xlsform\Dto\TypeMapping;

/**
 * The single source of truth for the FieldType ⇄ XLSForm `survey.type` mapping (Increment G7 /
 * docs/xlsform-interop-spec.md §3). Forward ({@see toXlsform}) drives export; reverse ({@see toFieldType})
 * drives import (Increment G7b). Kept deliberately free of any snapshot/DB knowledge so both the exporter
 * and the importer's pure parser can depend on it.
 *
 * The three multi-row lossy types — `matrix`, `likert_matrix`, `page_break` — get a base mapping here for
 * completeness, but their actual export expansion (one question per cell / per row / a group boundary) is
 * the exporter's job; the map only records that they are `exportOnly` and their round-trip `marker`.
 */
final class XlsformTypeMap
{
    /** Breadcrumbs written to the ignored `#meridian` survey column for own-export round-trip recovery. */
    public const MARKER_DURATION = 'meridian:duration';

    public const MARKER_LIKERT_MATRIX = 'meridian:likert_matrix';

    public const MARKER_MATRIX = 'meridian:matrix';

    public const MARKER_PAGE_BREAK = 'meridian:page_break';

    public const MARKER_HIDDEN = 'meridian:hidden';

    public const MARKER_EMAIL = 'meridian:email';

    public const MARKER_URL = 'meridian:url';

    public const MARKER_PHONE = 'meridian:phone';

    public const MARKER_CASCADING = 'meridian:cascading';

    /**
     * FieldType → the XLSForm representation (§3). Exhaustive `match` so every one of the 31 cases is
     * guaranteed a forward mapping (a new FieldType without an entry is a compile-visible omission).
     */
    public function toXlsform(FieldType $type): TypeMapping
    {
        return match ($type) {
            // Text
            FieldType::ShortText => new TypeMapping('text'),
            FieldType::LongText => new TypeMapping('text', appearance: 'multiline'),
            FieldType::Email => new TypeMapping('text', marker: self::MARKER_EMAIL),
            FieldType::Phone => new TypeMapping('text', appearance: 'numbers', marker: self::MARKER_PHONE),
            FieldType::Url => new TypeMapping('text', marker: self::MARKER_URL),

            // Numeric
            FieldType::Integer => new TypeMapping('integer'),
            FieldType::Decimal => new TypeMapping('decimal'),
            FieldType::Calculated => new TypeMapping('calculate'),

            // Date/Time
            FieldType::Date => new TypeMapping('date'),
            FieldType::Time => new TypeMapping('time'),
            FieldType::Datetime => new TypeMapping('dateTime'),
            FieldType::Duration => new TypeMapping('decimal', marker: self::MARKER_DURATION, exportOnly: true),

            // Choice
            FieldType::SingleSelect => new TypeMapping('select_one'),
            FieldType::MultiSelect => new TypeMapping('select_multiple'),
            FieldType::Dropdown => new TypeMapping('select_one', appearance: 'minimal'),
            FieldType::YesNo => new TypeMapping('select_one'),
            FieldType::CascadingSelect => new TypeMapping('select_one'),

            // Likert
            FieldType::LikertScale => new TypeMapping('select_one'),
            FieldType::LikertMatrix => new TypeMapping('select_one', marker: self::MARKER_LIKERT_MATRIX, exportOnly: true),

            // Geographic
            FieldType::Geopoint => new TypeMapping('geopoint'),
            FieldType::Geotrace => new TypeMapping('geotrace'),
            FieldType::Geoshape => new TypeMapping('geoshape'),

            // Media
            FieldType::FileUpload => new TypeMapping('file'),
            FieldType::ImageCapture => new TypeMapping('image'),
            FieldType::AudioCapture => new TypeMapping('audio'),
            FieldType::VideoCapture => new TypeMapping('video'),
            FieldType::Signature => new TypeMapping('image', appearance: 'signature'),

            // Structural
            FieldType::Note => new TypeMapping('note'),
            FieldType::PageBreak => new TypeMapping('note', marker: self::MARKER_PAGE_BREAK, exportOnly: true),
            FieldType::Hidden => new TypeMapping('text', appearance: 'hidden', marker: self::MARKER_HIDDEN),
            FieldType::Matrix => new TypeMapping('select_one', marker: self::MARKER_MATRIX, exportOnly: true),
        };
    }

    /**
     * XLSForm `survey.type` token (+ appearance + our marker) → FieldType, or null when the token has no
     * mapping (the parser turns the first null into `xlsform_unsupported_field_type`). `cascading_select`
     * is NOT resolved here — a `select_one` with a non-empty `choice_filter` is upgraded to cascading by the
     * parser (Increment G7b), which owns the choices/choice_filter context this map deliberately does not.
     *
     * Conservative, per §3: `select_one` is never auto-inferred as `yes_no`; `text` never re-infers
     * `email`/`url` (unless our own export marker says so); `duration`/`matrix`/`likert_matrix` are never
     * reconstructed even from their own marker (only `page_break` is).
     */
    public function toFieldType(string $type, ?string $appearance = null, ?string $marker = null): ?FieldType
    {
        $marker = $marker !== null ? trim($marker) : null;

        // Own-export markers we honour on import. page_break is restored (§3); duration/matrix/likert_matrix
        // are intentionally NOT (they downgrade to their flattened base representation).
        $fromMarker = match ($marker) {
            self::MARKER_PAGE_BREAK => FieldType::PageBreak,
            self::MARKER_EMAIL => FieldType::Email,
            self::MARKER_URL => FieldType::Url,
            self::MARKER_PHONE => FieldType::Phone,
            self::MARKER_HIDDEN => FieldType::Hidden,
            default => null,
        };
        if ($fromMarker !== null) {
            return $fromMarker;
        }

        $appearance = $appearance !== null ? strtolower(trim($appearance)) : null;
        // XLSForm select types carry the list_name inline ("select_one yn"); keep only the leading token.
        $token = strtolower(trim(explode(' ', trim($type))[0]));

        return match ($token) {
            'text' => match ($appearance) {
                'multiline' => FieldType::LongText,
                'numbers' => FieldType::Phone,
                'hidden' => FieldType::Hidden,
                default => FieldType::ShortText,
            },
            'integer' => FieldType::Integer,
            'decimal' => FieldType::Decimal,
            'calculate' => FieldType::Calculated,
            'date' => FieldType::Date,
            'time' => FieldType::Time,
            'datetime' => FieldType::Datetime,
            'select_one', 'select one' => $appearance === 'minimal' ? FieldType::Dropdown : FieldType::SingleSelect,
            'select_multiple', 'select multiple' => FieldType::MultiSelect,
            'geopoint' => FieldType::Geopoint,
            'geotrace' => FieldType::Geotrace,
            'geoshape' => FieldType::Geoshape,
            'file' => FieldType::FileUpload,
            'image' => $appearance === 'signature' ? FieldType::Signature : FieldType::ImageCapture,
            'audio' => FieldType::AudioCapture,
            'video' => FieldType::VideoCapture,
            'note' => FieldType::Note,
            default => null,
        };
    }

    /**
     * Whether a FieldType can be produced by import. The two object-valued grids have no reliable native
     * XLSForm equivalent, so they are export-only (§3) — a `#meridian` marker claiming one is downgraded to
     * its flattened `select_one` children plus a warning by the parser.
     */
    public function isImportable(FieldType $type): bool
    {
        return $type !== FieldType::Matrix && $type !== FieldType::LikertMatrix;
    }
}
