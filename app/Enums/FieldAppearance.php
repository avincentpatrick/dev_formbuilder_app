<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The layouts an author may CHOOSE for a question (`R-6c76bed2`, M130) — the typed vocabulary behind
 * `form_fields.appearance`, which used to be a free-text "Appearance hint" that nothing honoured.
 *
 * ⛔ THE VALUES ARE ODK'S OWN NAMES, AND ONLY THE TWO THAT ROUND-TRIP VERBATIM. For a type with no forced
 * appearance the XLSForm exporter writes the stored value unchanged, and `XlsformImportParser` keeps it
 * unchanged on the way back, so `columns-pack` and `columns` survive an export and an import on the two
 * list types. `minimal` is NEVER offered: `select_one` with `minimal` imports as a Dropdown
 * (`XlsformTypeMap::toFieldType()`), so a single choice stored with it would come back as another type.
 *
 * ⛔ `for()` IS A `match` WITH NO `default` — the {@see ValueShape} convention — so a thirty-second field
 * type is a PHPStan error until someone decides which layouts it takes, rather than silently taking none.
 *
 * ⚠️ A VALUE OUTSIDE THIS LIST IS NOT INVALID DATA, AND NOTHING MAY TREAT IT AS SUCH. XLSForm import
 * stores ODK appearances verbatim (`likert`, `quick`, `no-calendar` …) and the export writes them back;
 * the save request keeps a stored value unchanged, and the renderers ignore what they do not know. This
 * enum is what an author may choose, not what a row may hold.
 */
enum FieldAppearance: string
{
    /** Choices side by side, wrapping onto as many lines as they need. */
    case ColumnsPack = 'columns-pack';

    /** Choices in a responsive grid of equal columns. */
    case Columns = 'columns';

    /**
     * The layouts an author may choose for this type, in the order the builder offers them. An empty list
     * means the type has no layout setting; the default ("one per line" for the list types) is always the
     * absence of a value, never a case here.
     *
     * @return list<self>
     */
    public static function for(FieldType $type): array
    {
        return match ($type) {
            FieldType::SingleSelect, FieldType::MultiSelect => [self::ColumnsPack, self::Columns],

            // Text-like and numeric appearances (`multiline`, `numbers`, `thousands-sep`) either change the
            // type on import or change nothing a renderer here draws; the date ones (`month-year`, `year`)
            // change the stored value. Dropdown, Long text, Phone, Signature and Hidden have an appearance
            // FORCED on export, which would replace any stored one.
            FieldType::ShortText, FieldType::LongText, FieldType::Email, FieldType::Phone, FieldType::Url,
            FieldType::Integer, FieldType::Decimal, FieldType::Calculated,
            FieldType::Date, FieldType::Time, FieldType::Datetime, FieldType::Duration,
            FieldType::Dropdown, FieldType::YesNo, FieldType::CascadingSelect, FieldType::LikertScale,
            FieldType::LikertMatrix, FieldType::Matrix,
            FieldType::Geopoint, FieldType::Geotrace, FieldType::Geoshape,
            FieldType::FileUpload, FieldType::ImageCapture, FieldType::AudioCapture, FieldType::VideoCapture,
            FieldType::Signature, FieldType::Note, FieldType::PageBreak, FieldType::Hidden => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ColumnsPack => 'Side by side',
            self::Columns => 'In columns',
        };
    }
}
