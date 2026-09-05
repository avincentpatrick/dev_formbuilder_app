<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Templates\TemplateScopeResolver;

/**
 * Whether ONE field type may be a piping SOURCE — the field a template hole `${key}` names
 * (`docs/piping-output-encoding-design.md` §3.1, Increment H6a). Consumed by
 * {@see TemplateScopeResolver} at publish time.
 *
 * The question this answers is narrow: *is this answer a scalar that reads sensibly inside a sentence, and
 * does a value exist for it before the consuming text renders?* Three-valued, because "holds no answer at
 * all" is not the same refusal as "holds an answer a sentence cannot carry" — `note` and `page_break` are
 * prose and pagination that nobody fills in, while a `matrix` holds a real answer that simply has no text
 * rendering. Splitting them lets the publish gate say which mistake the author made.
 *
 * ── Why this is a `match` with NO `default` arm ─────────────────────────────────────────────────────
 * The device H8 introduced for {@see OcrFieldEligibility}, for the same reason. Every general
 * {@see FieldType} predicate that could plausibly compose this rule carries a `default =>` arm —
 * `isAdvanced()` (`:129`), `hasOptions()` (`:138`), `configEditor()` (`:161`), `isMedia()` (`:200`) — and
 * `isComposite()`/`isGeo()` are `===` chains, which widen just as silently. A rule composed from any of
 * them absorbs a 32nd field type as pipeable without anyone deciding that. Enumerating all 31 cases here
 * instead makes an unclassified type a PHPStan-level-8 error ("Match expression does not handle remaining
 * values"), merge-blocking in the `static-analysis` CI job, and an `UnhandledMatchError` at runtime.
 *
 * That forcing device only works from `app/` — `phpstan.neon` scans `app`, `database` and `routes` only,
 * with no baseline file — which is why this lives here and not in a test helper or a config array.
 *
 * ── Why it is NOT a reuse of OcrFieldEligibility ────────────────────────────────────────────────────
 * The two predicates genuinely differ, in exactly two places (`calculated` and `hidden`, documented on
 * their arms below), so the shared 17 are a coincidence of overlap rather than a shared rule. Piping is
 * also a WIDENING relative to OCR, which is the one change class that breaks H8's monotonicity invariant —
 * so that invariant is deliberately not inherited here, and neither is its test.
 *
 * ── Why it lives here, not on FieldType ─────────────────────────────────────────────────────────────
 * Piping source-eligibility is channel policy, not an intrinsic structural fact about a field type — the
 * same line {@see OcrFieldEligibility} draws, citing `XlsformTypeMap::isImportable()`. Keeping it out of
 * the catalog also leaves `FieldType`'s existing 31-arm matches untouched.
 */
enum PipingEligibility: string
{
    /** May be named by a template hole; renders through `SchemaValueFormatter::displayValue()`. */
    case Pipeable = 'pipeable';

    /** Holds no answer at all, so there is nothing for a hole to render. */
    case NoAnswer = 'no_answer';

    /** Holds an answer whose stored shape has no sensible rendering inside a sentence. */
    case Excluded = 'excluded';

    /**
     * The TOTAL classification of the 31-case {@see FieldType} catalog: 19 pipeable / 2 no-answer /
     * 10 excluded. Deliberately a `match` with NO `default` arm — see the class docblock.
     *
     * Note the arm clusters below do NOT follow `FieldType`'s own comment groupings, and must not:
     * `matrix` is declared under the catalog's `// Structural` block and `category()` maps it to
     * `FieldCategory::Structural`, but it is object-valued and belongs with `likert_matrix`. Classify by
     * §3.1's table, never by the catalog's grouping.
     */
    public static function for(FieldType $type): self
    {
        return match ($type) {
            // ── Respondent-supplied scalars ─────────────────────────────────────────────────────────
            // Exactly OcrFieldEligibility::Extractable: each holds a single value that reads as a noun
            // phrase inside a sentence. Choice types resolve to their author-defined LABEL via
            // displayValue(), so a piped `single_select` reads "Metro Manila", not "ncr".
            FieldType::ShortText, FieldType::LongText,
            FieldType::Email, FieldType::Phone, FieldType::Url,
            FieldType::Integer, FieldType::Decimal,
            FieldType::Date, FieldType::Time, FieldType::Datetime, FieldType::Duration,
            FieldType::SingleSelect, FieldType::MultiSelect, FieldType::Dropdown,
            FieldType::YesNo, FieldType::CascadingSelect,
            FieldType::LikertScale => self::Pipeable,

            // ── DIVERGES FROM OCR, deliberately (Doc #26 §3.1) ──────────────────────────────────────
            // OCR excludes `calculated` because a scanned value could only contradict the formula.
            // Piping is the opposite case: a running total is one of the most valuable things to pipe,
            // and it is a scalar the engine has already computed. Note the render-side consequence
            // recorded in §8 — a DRAFT document carries no calculated values (the structural normalizer
            // drops them on the way in and the merge happens at promote()), so a calculated hole
            // rendered against a draft is empty by §3.4.
            FieldType::Calculated => self::Pipeable,

            // ── DIVERGES FROM OCR, deliberately (Doc #26 §3.1) ──────────────────────────────────────
            // OCR calls `hidden` Neutral because paper never carries it. Piping is its primary consumer:
            // a URL-prefilled first name (H7) exists BEFORE the first label renders, which is precisely
            // what makes it a good source. It arrives as server-constrained UNTRUSTED input (H7), which
            // raises the stakes on the §5 output-encoding contract rather than lowering them.
            FieldType::Hidden => self::Pipeable,

            // ── Hold no respondent answer ───────────────────────────────────────────────────────────
            // Exactly {@see \App\Services\Submissions\SchemaValueFormatter}'s NON_DATA set — printed
            // prose and pagination, filled in by nobody.
            FieldType::Note, FieldType::PageBreak => self::NoAnswer,

            // ── Object-valued grids ─────────────────────────────────────────────────────────────────
            // `{row:{col:cell}}` / `{row:score}`. Already banned as expression operands by
            // ExpressionValidationGate, so one rule now governs both reference kinds. Without the
            // exclusion they would reach displayValue()'s is_array branch, which drops the row keys —
            // json_encoding each ROW for a matrix, and for a likert_matrix emitting nothing worse than
            // a bare list of scores with no row labels at all.
            // ⚠️ M74 GAVE displayValue() REAL ARMS FOR BOTH, so the "machine noise" half of this
            // reasoning is spent: a grid now renders `q1: c1=v1; q2: c1=v3`. THE EXCLUSION STANDS
            // UNCHANGED on the other half — a two-dimensional answer flattened into the middle of a
            // sentence is not a sentence, and §3.1's revisit trigger (a stated need to pipe one into
            // prose) has not fired.
            FieldType::Matrix, FieldType::LikertMatrix => self::Excluded,

            // ── Object-valued GeoJSON envelopes ─────────────────────────────────────────────────────
            // Also already banned as expression operands. displayValue() CAN format these
            // ("lat, lon (±m)"), so this is a deliberate scope boundary rather than an impossibility —
            // §3.1 records the revisit trigger: a stated need to pipe a captured location into prose.
            FieldType::Geopoint, FieldType::Geotrace, FieldType::Geoshape => self::Excluded,

            // ── Attachment-reference envelopes ──────────────────────────────────────────────────────
            // The stored answer is a list of attachment references. ⚠️ M74 CORRECTED THIS SENTENCE'S
            // MECHANISM AND THEN SPENT IT: such an answer never reached the "json_encode scalar
            // fallback" — being a list, it hit the is_array branch first and was json_encoded PER
            // ENVELOPE — and displayValue() now names the files instead. THE EXCLUSION STANDS on the
            // remaining ground: a filename is not an answer to the question a hole is asking, and a
            // piped one would imply the file itself is reachable from the sentence. Excluded
            // EXPLICITLY rather than by inheriting an expression-operand ban — §3.1 notes that media
            // types, unlike composite and geo, are NOT currently banned as expression operands, so there
            // is no ban here to lean on.
            FieldType::FileUpload, FieldType::ImageCapture,
            FieldType::AudioCapture, FieldType::VideoCapture, FieldType::Signature => self::Excluded,
        };
    }
}
