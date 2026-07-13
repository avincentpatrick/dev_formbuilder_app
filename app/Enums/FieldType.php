<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The 31-value field-type catalog across 8 categories (data-dictionary FieldType catalog, carried
 * forward from legacy's 8-category `input_types` lookup, expressed as code with no lookup table).
 * Backs `form_fields.field_type` and `field_library.field_type`.
 *
 * `matrix` is a generic grid (each cell independently typed) and is deliberately distinct from
 * `likert_matrix` (a score-only Likert grid). Advanced types (geo*, *_capture, cascading_select,
 * *matrix) are surfaced in the Phase-1 builder with a baseline config editor; their rich editors and
 * runtime follow the form engine (ADR-0004).
 */
enum FieldType: string
{
    // Text
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case Email = 'email';
    case Phone = 'phone';
    case Url = 'url';

    // Numeric
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Calculated = 'calculated';

    // Date/Time
    case Date = 'date';
    case Time = 'time';
    case Datetime = 'datetime';
    case Duration = 'duration';

    // Choice
    case SingleSelect = 'single_select';
    case MultiSelect = 'multi_select';
    case Dropdown = 'dropdown';
    case YesNo = 'yes_no';
    case CascadingSelect = 'cascading_select';

    // Likert
    case LikertScale = 'likert_scale';
    case LikertMatrix = 'likert_matrix';

    // Geographic
    case Geopoint = 'geopoint';
    case Geotrace = 'geotrace';
    case Geoshape = 'geoshape';

    // Media
    case FileUpload = 'file_upload';
    case ImageCapture = 'image_capture';
    case AudioCapture = 'audio_capture';
    case VideoCapture = 'video_capture';
    case Signature = 'signature';

    // Structural
    case Note = 'note';
    case PageBreak = 'page_break';
    case Hidden = 'hidden';
    case Matrix = 'matrix';

    /** The category this field type belongs to (drives the builder palette grouping). */
    public function category(): FieldCategory
    {
        return match ($this) {
            self::ShortText, self::LongText, self::Email, self::Phone, self::Url => FieldCategory::Text,
            self::Integer, self::Decimal, self::Calculated => FieldCategory::Numeric,
            self::Date, self::Time, self::Datetime, self::Duration => FieldCategory::DateTime,
            self::SingleSelect, self::MultiSelect, self::Dropdown, self::YesNo, self::CascadingSelect => FieldCategory::Choice,
            self::LikertScale, self::LikertMatrix => FieldCategory::Likert,
            self::Geopoint, self::Geotrace, self::Geoshape => FieldCategory::Geographic,
            self::FileUpload, self::ImageCapture, self::AudioCapture, self::VideoCapture, self::Signature => FieldCategory::Media,
            self::Note, self::PageBreak, self::Hidden, self::Matrix => FieldCategory::Structural,
        };
    }

    /** Human label for the builder palette / config panel (single source for the frontend catalog). */
    public function label(): string
    {
        return match ($this) {
            self::ShortText => 'Short text',
            self::LongText => 'Long text',
            self::Email => 'Email',
            self::Phone => 'Phone',
            self::Url => 'URL',
            self::Integer => 'Whole number',
            self::Decimal => 'Decimal number',
            self::Calculated => 'Calculated',
            self::Date => 'Date',
            self::Time => 'Time',
            self::Datetime => 'Date & time',
            self::Duration => 'Duration',
            self::SingleSelect => 'Single choice',
            self::MultiSelect => 'Multiple choice',
            self::Dropdown => 'Dropdown',
            self::YesNo => 'Yes / No',
            self::CascadingSelect => 'Cascading select',
            self::LikertScale => 'Likert scale',
            self::LikertMatrix => 'Likert matrix',
            self::Geopoint => 'GPS point',
            self::Geotrace => 'GPS line',
            self::Geoshape => 'GPS area',
            self::FileUpload => 'File upload',
            self::ImageCapture => 'Photo',
            self::AudioCapture => 'Audio',
            self::VideoCapture => 'Video',
            self::Signature => 'Signature',
            self::Note => 'Note / label',
            self::PageBreak => 'Page break',
            self::Hidden => 'Hidden field',
            self::Matrix => 'Matrix (grid)',
        };
    }

    /**
     * Advanced types get only a baseline config editor in the Phase-1 builder — their rich editors and
     * runtime follow the form engine (ADR-0004). The builder surfaces them fully but flags the gap.
     */
    public function isAdvanced(): bool
    {
        return match ($this) {
            self::Geopoint, self::Geotrace, self::Geoshape,
            self::ImageCapture, self::AudioCapture, self::VideoCapture,
            self::CascadingSelect, self::Matrix, self::LikertMatrix => true,
            default => false,
        };
    }

    /** Whether this type offers an author-defined option list (drives the Choices config editor). */
    public function hasOptions(): bool
    {
        return match ($this) {
            self::SingleSelect, self::MultiSelect, self::Dropdown, self::LikertScale => true,
            default => false,
        };
    }

    /**
     * The builder config editor this type needs beyond the shared Basics/Validation/Advanced tabs, or null
     * for none. `'choices'` is the plain option-list editor (all {@see hasOptions()} types); the others are
     * dedicated per-type editors. Single source the builder palette ships to the client so the config panel
     * never re-lists which type wires which editor. `matrix`/`likert_matrix` (Increment G4b) are the
     * object-valued grids and use their own row/column/cell editors; `geo` (Increment G5b2b) is the map
     * config editor (capture/accuracy + default centre/zoom) shared by all three geospatial types.
     */
    public function configEditor(): ?string
    {
        return match ($this) {
            self::SingleSelect, self::MultiSelect, self::Dropdown, self::LikertScale => 'choices',
            self::CascadingSelect => 'cascading',
            self::Matrix => 'matrix',
            self::LikertMatrix => 'likert_matrix',
            self::Geopoint, self::Geotrace, self::Geoshape => 'geo',
            default => null,
        };
    }

    /**
     * The two object-valued grid types (Increment G4b): `matrix` persists `{row:{col:cell}}` and
     * `likert_matrix` persists `{row:score}`. They are handled by the dedicated `processComposites` pass
     * (never routed through scalar coercion) and may not be referenced in expressions or nested in a
     * repeatable section (enforced at publish).
     */
    public function isComposite(): bool
    {
        return $this === self::Matrix || $this === self::LikertMatrix;
    }

    /**
     * The three geospatial types (Increment G5b1 / ADR-0006): `geopoint` → GeoJSON `Point`, `geotrace` →
     * `LineString`, `geoshape` → closed `Polygon`. Their answer is an object-valued GeoJSON envelope
     * (`{type, coordinates, accuracy?}`), so — like composites — they are handled by the dedicated
     * `processGeo` pass (never routed through scalar coercion, where PHP `isEmpty([])` diverges from TS
     * `isEmpty({})`) and may not be referenced in expressions or nested in a repeatable section.
     */
    public function isGeo(): bool
    {
        return $this === self::Geopoint || $this === self::Geotrace || $this === self::Geoshape;
    }
}
