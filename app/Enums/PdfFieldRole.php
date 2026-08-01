<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Submissions\SubmissionPdfPresenter;

/**
 * How ONE field type appears in a submission PDF (Increment H17). Consumed by
 * {@see SubmissionPdfPresenter}.
 *
 * The PDF's contract is narrower than any existing read surface: it is a record of *what the
 * respondent actually saw*. That makes the question here "was this field on the respondent's
 * screen, and did it carry an answer?" — not "does this field hold data?" (the inbox's question,
 * `SchemaValueFormatter::isDataField()`) and not "can a keyer type into it?" (the encode channel's
 * question, `EncodeFormPresenter::OMITTED`). All three sets disagree, and they are all correct:
 *
 *   NON_DATA        note, page_break                    — holds no answer          (inbox, export)
 *   OMITTED         page_break, calculated              — a keyer cannot type it   (encode)
 *   RENDERS_NOTHING hidden, calculated, page_break      — a respondent never saw it (public runtime)
 *
 * ── The one membership that MUST NOT DRIFT ──────────────────────────────────────────────────────
 * {@see self::Omitted} is the PHP twin of `RENDERS_NOTHING` in
 * `resources/public-runtime/lib/schema-mapping.ts` — the set the guest SPA uses to decide a field
 * is not rendered to a respondent at all. If the two ever disagree, the PDF claims the respondent
 * saw something they did not (or hides something they did), which is the whole value of the
 * document. `PdfFieldRoleParityTest` asserts the two sets are identical, in both directions.
 *
 * {@see self::Prose} has NO TypeScript twin and deliberately so — it is not a visibility question.
 * The SPA renders a `note` (it is instructions, a consent statement, a section preamble) and so
 * does the PDF; the two agree that a respondent saw it. They differ only on what to do next, and
 * "render the label with no answer slot" is a PDF layout concern the runtime has no opinion about.
 *
 * ── Why this is a `match` with NO `default` arm ─────────────────────────────────────────────────
 * The device H8 introduced for {@see OcrFieldEligibility} and H6a reused for
 * {@see PipingEligibility}, for the same reason: every general {@see FieldType} predicate that
 * could plausibly compose this rule carries a `default =>` arm, so a rule built from them absorbs
 * a 32nd field type silently — and here the silent default would be "the respondent saw it",
 * i.e. a new field type leaks into a record of what someone was shown. Enumerating all 31 cases
 * makes an unclassified type a PHPStan-level-8 error, merge-blocking in `static-analysis`.
 *
 * That forcing device only works from `app/` — `phpstan.neon` scans `app`, `database` and `routes`
 * only, with no baseline file — which is why this lives here and not in the presenter.
 *
 * ── Why it is NOT a reuse of PipingEligibility ──────────────────────────────────────────────────
 * They answer opposite questions and disagree on the two types that matter most. `hidden` and
 * `calculated` are the two H6a deliberately WIDENED into `Pipeable` — precisely because their
 * values exist without the respondent doing anything. That property makes them excellent piping
 * sources and makes them invisible on a PDF. A shared rule would have to be wrong for one of them.
 */
enum PdfFieldRole: string
{
    /** The respondent saw a labelled control; render label + rendered answer. */
    case Answer = 'answer';

    /** The respondent saw text but there is no answer; render the label alone. */
    case Prose = 'prose';

    /** The respondent never saw this field; it must not appear in the PDF at all. */
    case Omitted = 'omitted';

    /**
     * The TOTAL classification of the 31-case {@see FieldType} catalog: 27 answer / 1 prose /
     * 3 omitted. Deliberately a `match` with NO `default` arm — see the class docblock.
     */
    public static function for(FieldType $type): self
    {
        return match ($type) {
            // ── Rendered to the respondent, and carry an answer ─────────────────────────────────
            // Everything a respondent can type, pick, capture or draw. Object-valued types
            // (matrix/likert_matrix, the three geo envelopes) and the five media types are all
            // INCLUDED here even though PipingEligibility excludes them: a hole in a sentence needs
            // a scalar, but a PDF row has a whole cell to work with and the respondent demonstrably
            // saw the control. How each renders is SchemaValueFormatter::displayValue()'s problem,
            // not a visibility question.
            FieldType::ShortText, FieldType::LongText,
            FieldType::Email, FieldType::Phone, FieldType::Url,
            FieldType::Integer, FieldType::Decimal,
            FieldType::Date, FieldType::Time, FieldType::Datetime, FieldType::Duration,
            FieldType::SingleSelect, FieldType::MultiSelect, FieldType::Dropdown,
            FieldType::YesNo, FieldType::CascadingSelect,
            FieldType::LikertScale, FieldType::LikertMatrix, FieldType::Matrix,
            FieldType::Geopoint, FieldType::Geotrace, FieldType::Geoshape,
            FieldType::FileUpload, FieldType::ImageCapture,
            FieldType::AudioCapture, FieldType::VideoCapture, FieldType::Signature => self::Answer,

            // ── Rendered to the respondent, carries no answer ───────────────────────────────────
            // A note is the one field type that is visible AND answerless. The inbox drops it
            // (SchemaValueFormatter::NON_DATA) because an inbox lists answers; a PDF keeps it
            // because a record of what someone was shown that omits the consent paragraph they
            // were shown is not a record of what they were shown. It carries no value, so it never
            // reaches displayValue() and cannot be a relevance-pruned key.
            FieldType::Note => self::Prose,

            // ── Never rendered to the respondent ────────────────────────────────────────────────
            // Byte-for-byte `RENDERS_NOTHING` (schema-mapping.ts), asserted by
            // PdfFieldRoleParityTest. `hidden` and `calculated` hold real, often important values
            // — a URL-prefilled case number (H7), a computed total — but the respondent never saw
            // either, so neither belongs in a record of what they saw. `page_break` is pagination.
            //
            // Note what this deliberately gives up: a `calculated` total the respondent DID see
            // rendered into a piped label still reaches the PDF, through the hole in that label
            // (PipingEligibility::Pipeable). The value is not lost — it appears exactly where the
            // respondent saw it, which is the correct place for it.
            FieldType::Hidden, FieldType::Calculated, FieldType::PageBreak => self::Omitted,
        };
    }
}
