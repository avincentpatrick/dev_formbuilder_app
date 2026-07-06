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
}
