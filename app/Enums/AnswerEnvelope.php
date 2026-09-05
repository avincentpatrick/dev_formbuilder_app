<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Submissions\SchemaValueFormatter;

/**
 * Which SHAPE a stored answer arrives in, for the surfaces that must summarise it before rendering
 * (Increment M74). This is the partition {@see SchemaValueFormatter} needs
 * and no existing enum expresses.
 *
 * ⛔ WHY THIS EXISTS AT ALL, AND IT IS THE ROW'S OWN HISTORY. `displayValue()` grew arms for `yes_no`,
 * for geo and for option-bearing choice types, and everything else fell through to a generic
 * `is_array` join that `json_encode`s each non-scalar element. Two object-valued families —
 * media and the two grids — were added to `FieldType` afterwards and nobody added an arm, so a photo
 * answer rendered as `{"id":"…","mime":"image\/jpeg"}` on the inbox, the export, the PDF and, through
 * `SubmissionRowProjector::answerValues()`, into Google Sheets and Airtable.
 *
 * ⛔ SO IT IS A `match` WITH NO `default`, AND THAT IS THE WHOLE POINT RATHER THAN A STYLE CHOICE.
 * A predicate would have worked today and re-opened the defect tomorrow: `FieldType::isMedia()`,
 * `isAdvanced()`, `hasOptions()` and `configEditor()` all carry `default => false`, so a thirty-second
 * field type joins the silent majority. Here it is a PHPStan level-8 error, which `phpstan.neon` scans
 * and CI blocks on. The device is {@see OcrFieldEligibility}'s, reused deliberately.
 *
 * ⛔ AND {@see AnalyticsFieldEligibility} IS NOT THIS PARTITION, THOUGH IT LOOKS LIKE IT. It classifies
 * `MultiSelect` and `CascadingSelect` as `Structured` alongside the grids and media. Those two must
 * keep the label-resolving `is_array` join — routing them through a grid arm renders `Cough; Fever`
 * as a grid and reddens the `fmt_multi_select_joins` and `fmt_cascading_select_resolves_labels`
 * vectors. The observation that a channel-policy enum exists is not new; this partition is.
 */
enum AnswerEnvelope: string
{
    /** A scalar, or a list of scalars — the generic join renders it correctly. Twenty-one types. */
    case None = 'none';

    /** A GeoJSON envelope: `{type, coordinates, accuracy?}`. */
    case Geo = 'geo';

    /** A list of attachment-reference envelopes: `[{id, mime?, name?, size?, …}]`. */
    case Media = 'media';

    /** A two-level grid: `{row: {column: cell}}`. */
    case Grid = 'grid';

    /** A flat one-score-per-row grid: `{row: score}`. */
    case ScoreGrid = 'score_grid';

    public static function for(FieldType $type): self
    {
        return match ($type) {
            FieldType::Geopoint, FieldType::Geotrace, FieldType::Geoshape => self::Geo,

            FieldType::FileUpload, FieldType::ImageCapture, FieldType::AudioCapture,
            FieldType::VideoCapture, FieldType::Signature => self::Media,

            FieldType::Matrix => self::Grid,
            FieldType::LikertMatrix => self::ScoreGrid,

            FieldType::ShortText, FieldType::LongText, FieldType::Email, FieldType::Phone,
            FieldType::Url, FieldType::Integer, FieldType::Decimal, FieldType::Calculated,
            FieldType::Date, FieldType::Time, FieldType::Datetime, FieldType::Duration,
            FieldType::SingleSelect, FieldType::MultiSelect, FieldType::Dropdown, FieldType::YesNo,
            FieldType::CascadingSelect, FieldType::LikertScale, FieldType::Note,
            FieldType::PageBreak, FieldType::Hidden => self::None,
        };
    }
}
