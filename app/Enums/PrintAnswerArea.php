<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Forms\BlankFormPrintPresenter;

/**
 * What ONE field type gets on a PRINTED BLANK FORM (Increment I12). Consumed by
 * {@see BlankFormPrintPresenter}; the geometry of each area is
 * `docs/ocr-pipeline-design.md` §2.5.
 *
 * The question here is "can a human write this answer on paper with a pen, and in what shape?" — and
 * that is a fourth, genuinely different question from the three this codebase already asks about a
 * field type. All four sets disagree, and all four are correct:
 *
 *   NON_DATA        note, page_break                    — holds no answer          (inbox, export)
 *   OMITTED         page_break, calculated              — a keyer cannot type it   (encode)
 *   RENDERS_NOTHING hidden, calculated, page_break      — a respondent never saw it (public runtime,
 *                                                         and {@see PdfFieldRole::Omitted})
 *   this enum       hidden, calculated                  — a pen cannot supply it
 *
 * ── Why this is NOT a reuse of {@see PdfFieldRole}, which is the tempting one ────────────────────
 * `PdfFieldRole` answers "did the respondent SEE this field?", because a submission PDF is a record
 * of what somebody was shown. It therefore classifies every media type and all three geo envelopes
 * as {@see PdfFieldRole::Answer} — correctly, since the respondent demonstrably saw those controls
 * on a screen. Reusing it here would put a writable box under "Photo of household" on a sheet of
 * paper, inviting somebody to write into an area no scan will ever produce an `attachments` row
 * from. Those types are {@see self::Unavailable} here, and the paper says so out loud.
 *
 * ── Why this is NOT {@see OcrFieldEligibility} either, and the two MUST NOT be collapsed ─────────
 * That enum answers "can an extraction stage lift this answer off a scan?" — a channel-policy
 * question about the OCR pipeline. This one answers "is this a form a field team can carry?" A
 * printed blank form is the INSTRUMENT, not the extraction target, so the two deliberately disagree
 * on three type groups, decided with the user 2026-08-09:
 *
 *   - `matrix` / `likert_matrix` are {@see OcrFieldEligibility::Excluded} (a flat scan/linelist row
 *     model cannot represent a grid) but are {@see self::Grid} here — paper has handled grids since
 *     long before this product existed, and dropping them would mean the printed form is not the
 *     form. A version containing one simply is not OCR-eligible; the footer notice says so.
 *   - `signature` is `Excluded` (a scan cannot produce an `attachments` row) but is
 *     {@see self::SignatureLine} here — the signature's paper artefact is the whole point of a
 *     signature, and §2's own table already flags it as the one exclusion a future crop-region
 *     capability could reclaim.
 *   - `page_break` is {@see OcrFieldEligibility::Neutral} (it holds no answer) but is
 *     {@see self::PageBreak} here, where it means something concrete: paper has pages.
 *
 * `tests/Unit/Forms/PrintAnswerAreaTest.php` pins those disagreements as assertions, so a later
 * refactor cannot quietly fold one enum into the other.
 *
 * ── Why this is a `match` with NO `default` arm ─────────────────────────────────────────────────
 * The device {@see OcrFieldEligibility} introduced, {@see PdfFieldRole} reused and
 * {@see PipingEligibility} reused again, for the same reason: every general {@see FieldType}
 * predicate that could plausibly compose this rule carries a `default =>` arm, so a rule built from
 * them absorbs a 32nd field type silently. Here the silent default would put a blank writing area
 * under a field type nobody has decided a pen can fill. Enumerating all 31 cases makes an
 * unclassified type a PHPStan-level-8 error ("Match expression does not handle remaining values"),
 * which is merge-blocking in the `static-analysis` CI job, and an `UnhandledMatchError` at runtime.
 *
 * That forcing device only works from `app/` — `phpstan.neon` scans `app`, `database` and `routes`
 * only, with no baseline file — which is why this lives here and not in the presenter.
 */
enum PrintAnswerArea: string
{
    /**
     * A row of separated character boxes ("comb" fields). The single biggest handwriting-recognition
     * win available in a layout decision: segmentation is free when the characters are pre-separated,
     * which is why every census and bank form in the world uses them. Decided with the user
     * 2026-08-09 for exactly that reason — this layout is what H1d's ICR bake-off will be scored
     * against.
     */
    case Comb = 'comb';

    /** One multi-line bordered box. Free prose has no character count to comb. */
    case Ruled = 'ruled';

    /** The option list, each with a drawn box to tick. */
    case Choices = 'choices';

    /** A real table: `config.rows` down the side, `config.columns` across, a drawn box per cell. */
    case Grid = 'grid';

    /** A single ruled line. Not a comb: nobody signs inside boxes. */
    case SignatureLine = 'signature_line';

    /** Printed text with no answer area at all — instructions, a consent paragraph, a preamble. */
    case Prose = 'prose';

    /**
     * The label prints, followed by a stated marker, and NO writing area.
     *
     * The alternative — omitting these silently — was rejected with the user: an enumerator holding
     * the paper would have no way to know the digital form asks for a GPS reading and a photo, and
     * would have no prompt to capture them by another means. Printing a writable box was rejected
     * too, because it invites somebody to write a coordinate into an area nothing will ever read.
     */
    case Unavailable = 'unavailable';

    /** A hard page break. The one structural type that means MORE on paper than on a screen. */
    case PageBreak = 'page_break';

    /** Nothing is printed. The server supplies the value; a pen never can. */
    case Omitted = 'omitted';

    /**
     * The TOTAL classification of the 31-case {@see FieldType} catalog: 10 comb / 1 ruled /
     * 6 choices / 2 grid / 1 signature / 1 prose / 7 unavailable / 1 page break / 2 omitted.
     * Deliberately a `match` with NO `default` arm — see the class docblock.
     */
    public static function for(FieldType $type): self
    {
        return match ($type) {
            // ── Respondent-supplied scalars: a bounded run of characters on a line ───────────────
            // `email`/`phone`/`url` are validated short text and comb exactly as well. The date and
            // time types comb into FIXED groups (DD MM YYYY, HH MM) rather than a free run, which
            // is what makes a handwritten date machine-readable at all — see the presenter's
            // `combGroups()`. `duration` is a scalar written on a line, per ocr-pipeline §2.
            FieldType::ShortText,
            FieldType::Email, FieldType::Phone, FieldType::Url,
            FieldType::Integer, FieldType::Decimal,
            FieldType::Date, FieldType::Time, FieldType::Datetime, FieldType::Duration => self::Comb,

            // ── Free prose: unbounded, so there is nothing to comb ───────────────────────────────
            FieldType::LongText => self::Ruled,

            // ── Author-defined option lists ──────────────────────────────────────────────────────
            // `dropdown` prints identically to `single_select`: a dropdown is a SCREEN affordance
            // and paper has no such thing, so the options are simply listed. `cascading_select`
            // carries level/parent metadata alongside value+label, which the print ignores for the
            // same reason `SchemaValueFormatter::optionLabels()` ignores it — the labels are the
            // part a person reads. `likert_scale` is one circled row of options, the archetypal
            // paper instrument.
            FieldType::SingleSelect, FieldType::MultiSelect, FieldType::Dropdown,
            FieldType::YesNo, FieldType::CascadingSelect,
            FieldType::LikertScale => self::Choices,

            // ── Grids. OCR-`Excluded`, and printed anyway — see the class docblock ───────────────
            FieldType::Matrix, FieldType::LikertMatrix => self::Grid,

            // ── The one media type whose artefact IS the ink on the paper ────────────────────────
            FieldType::Signature => self::SignatureLine,

            // ── Printed, answered by nobody ──────────────────────────────────────────────────────
            FieldType::Note => self::Prose,

            // ── Real questions a pen cannot answer: named, marked, given no box ──────────────────
            // The three geo envelopes need a device to read a position; the four remaining media
            // types need a device to record one. Both are `Excluded` for OCR as well, but for a
            // different reason (a scan cannot produce an `attachments` row), and that coincidence
            // must not be mistaken for the same rule.
            FieldType::Geopoint, FieldType::Geotrace, FieldType::Geoshape,
            FieldType::FileUpload, FieldType::ImageCapture,
            FieldType::AudioCapture, FieldType::VideoCapture => self::Unavailable,

            // ── Pagination, which paper takes literally ──────────────────────────────────────────
            FieldType::PageBreak => self::PageBreak,

            // ── Never on the paper ───────────────────────────────────────────────────────────────
            // `hidden` is sourced from a URL parameter or an authored literal (H7) and `calculated`
            // is recomputed by the pipeline, so a handwritten value could only contradict the
            // server. This is the one place where this enum and `PdfFieldRole::Omitted` agree
            // exactly for the same underlying reason, minus `page_break`.
            FieldType::Hidden, FieldType::Calculated => self::Omitted,
        };
    }

    /** Whether this area puts any ink on the page at all. */
    public function isPrinted(): bool
    {
        return $this !== self::Omitted;
    }
}
