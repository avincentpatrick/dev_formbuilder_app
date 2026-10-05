<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Forms\StructuralValidationGate;

/**
 * Which SHAPE a field's VALUE has, for the surfaces that must decide what may be asserted about it
 * (Increment M112). This is the partition the validation editor and the publish gate need, and no
 * existing enum expresses it.
 *
 * ⛔ WHY IT IS KEYED ON A SHAPE AND NOT ON `FieldType`. Thirty-one types by eleven rule types is not a
 * table anybody can read or keep right; thirteen by eleven is. The row that filed this said so, and the
 * saving is real rather than cosmetic: twelve of the thirteen shapes answer every rule type identically
 * for every member type.
 *
 * ⛔ SO IT IS A `match` WITH NO `default`, AND THAT IS THE WHOLE POINT RATHER THAN A STYLE CHOICE.
 * A predicate would have worked today and re-opened the defect tomorrow: `FieldType::isMedia()`,
 * `isAdvanced()`, `hasOptions()` and `configEditor()` all carry `default => false`, so a thirty-second
 * field type joins the silent majority. `docs/piping-output-encoding-design.md:63` names `hasOptions()`
 * explicitly as the predicate a gate must NOT be composed from, for exactly that reason. Here an
 * unclassified type is a PHPStan level-8 error, which `phpstan.neon` scans and CI blocks on. The device
 * is {@see OcrFieldEligibility}'s, reused deliberately — and it lives HERE rather than on `FieldType`
 * for the reason written out at `OcrFieldEligibility.php:24-28`.
 *
 * ⚠️ THIS IS THE SEVENTH PARTITION OF THESE SAME THIRTY-ONE CASES, AND SAYING SO IS THE POINT.
 * {@see AnswerEnvelope} partitions the stored answer's container; {@see FieldCategory} groups the
 * palette; {@see IndexedDataType} types the projected index column; {@see AnalyticsFieldEligibility},
 * {@see PrintAnswerArea} and `FieldInput.vue`'s `control` computed each partition for their own surface. None of
 * them answers "what may be asserted about this value", which is why this one exists — but a reader
 * reaching for an eighth should check those six first.
 *
 * ⛔ THE THREE SPLITS THAT LOOK ARBITRARY ARE THE THREE THAT WERE MEASURED, AND EACH PROTECTS AN AUTHOR FROM
 * BUILDING A FORM NOBODY CAN SUBMIT.
 *
 *   - `Temporal` vs `Duration`. `duration` is grouped with the date/time types by every other registry
 *     in this directory, and `XlsformTypeMap::toXlsform()` maps it to `decimal` — because its stored
 *     answer IS a number while a date's is not. `Coercion::NUMERIC_RE` is `/^-?[0-9]+(\.[0-9]+)?$/`, so
 *     `2026-01-15` is not numeric-like. `StructuredRuleEvaluator`'s `MinValue` arm reads
 *     `isEmpty($answer) || (isNumericLike($answer) && …)`, and `ExpressionEvaluator`'s ordered
 *     comparison returns `false` on a NaN operand. **So `min_value` on a date, or
 *     `greater_than_field` between two dates, does not merely fail to constrain — it fails CLOSED, and
 *     every non-empty answer becomes invalid.** The field is then unanswerable with no way for the
 *     respondent to discover why. Ordering temporal values is real work and is filed as its own row;
 *     a regex pretending to be a type is not it.
 *   - `Choice` vs `Scale`. Both carry an author-defined option list, so {@see carriesOptionList()}
 *     returns true for both. They differ on `min_value`/`max_value`: a `likert_scale`'s option values
 *     are a numeric score by construction, while a `single_select`'s are arbitrary strings — where the
 *     same fail-closed comparison above would make the field unanswerable.
 *   - `Choice` vs `MultipleChoice` (Increment M134, `R-87160c81`). One value against a list: `equals()` makes an
 *     array on either side false, so "required if hobbies = reading" on a multi-select can never hold — and
 *     `!=` always does. {@see allowsOperator()} refuses `eq`/`neq` there; `contains` is the membership test.
 *
 * ⛔ AND `yes_no` IS `Boolean`, NOT `Choice`. Its two options are fixed and are stored nowhere, so a
 * gate that asserts `Choice` fields resolve an option list would refuse EVERY yes/no field in the
 * product. {@see StructuralValidationGate} depends on that distinction, and
 * `tests/Unit/Forms/ValueShapeTest.php` pins it as a named case rather than leaving it to the totality
 * assertion, because the totality assertion passes either way.
 */
enum ValueShape: string
{
    /** Free text, and the types that are text with a keyboard hint. `hidden` is text it never shows. */
    case Text = 'text';

    /** A number the evaluator can order: `integer`, `decimal`, `calculated`. */
    case Number = 'number';

    /** A date or time. Ordered by an EXPRESSION since M134, but never by a structured rule — see the class docblock. */
    case Temporal = 'temporal';

    /** An elapsed quantity, stored as a number and therefore orderable. */
    case Duration = 'duration';

    /** One value from an author-defined option list of arbitrary strings: `single_select`, `dropdown`. */
    case Choice = 'choice';

    /** Any number of values from an author-defined option list: `multi_select`. A LIST, never one value. */
    case MultipleChoice = 'multiple_choice';

    /** One value from an author-defined option list of numeric scores. */
    case Scale = 'scale';

    /** A fixed two-valued answer whose options are not stored. */
    case Boolean = 'boolean';

    /** An author-defined list of dependent levels (`cascading_select`). */
    case Hierarchy = 'hierarchy';

    /** A GeoJSON envelope. */
    case Geo = 'geo';

    /** A list of attachment-reference envelopes. */
    case Attachment = 'attachment';

    /** An object-valued grid: `{row: {column: cell}}` or `{row: score}`. */
    case Grid = 'grid';

    /** Carries no answer at all: `note`, `page_break`. */
    case NoAnswer = 'no_answer';

    public static function for(FieldType $type): self
    {
        return match ($type) {
            FieldType::ShortText, FieldType::LongText, FieldType::Email,
            FieldType::Phone, FieldType::Url, FieldType::Hidden => self::Text,

            FieldType::Integer, FieldType::Decimal, FieldType::Calculated => self::Number,

            FieldType::Date, FieldType::Time, FieldType::Datetime => self::Temporal,

            FieldType::Duration => self::Duration,

            FieldType::SingleSelect, FieldType::Dropdown => self::Choice,

            FieldType::MultiSelect => self::MultipleChoice,

            FieldType::LikertScale => self::Scale,

            FieldType::YesNo => self::Boolean,

            FieldType::CascadingSelect => self::Hierarchy,

            FieldType::Geopoint, FieldType::Geotrace, FieldType::Geoshape => self::Geo,

            FieldType::FileUpload, FieldType::ImageCapture, FieldType::AudioCapture,
            FieldType::VideoCapture, FieldType::Signature => self::Attachment,

            FieldType::Matrix, FieldType::LikertMatrix => self::Grid,

            FieldType::Note, FieldType::PageBreak => self::NoAnswer,
        };
    }

    /**
     * Whether a field of this shape carries an author-defined option list that publish must be able to
     * resolve — the total replacement for `FieldType::hasOptions()` at a gate.
     *
     * ⛔ The membership is IDENTICAL to `hasOptions()` ({@see FieldType::hasOptions()}): `single_select`,
     * `multi_select`, `dropdown`, `likert_scale`. What differs is that this one has no `default` arm, so
     * a thirty-second field type cannot be absorbed into "no options" in silence. That, and not the
     * membership, is what `docs/piping-output-encoding-design.md:63` requires of a gate.
     *
     * ⚠️ `Hierarchy` is deliberately FALSE. `cascading_select` carries `levels`, not a flat `options`
     * list, and `StructuralValidationGate::assertCascadingResolves()` already owns it — returning true
     * here would run the flat-list check over a shape it cannot read.
     */
    public function carriesOptionList(): bool
    {
        return match ($this) {
            self::Choice, self::MultipleChoice, self::Scale => true,
            self::Text, self::Number, self::Temporal, self::Duration, self::Boolean,
            self::Hierarchy, self::Geo, self::Attachment, self::Grid, self::NoAnswer => false,
        };
    }

    /**
     * Whether a validation rule of this kind may be asserted about a value of this shape.
     *
     * ⛔ THE CONDITIONAL FAMILY IS NOT A CONSTRAINT AND IS ALLOWED ALMOST EVERYWHERE.
     * `required_if`, `required_with`, `skip_if` and `skip_with` gate whether a field is required or
     * skipped; they assert nothing about the value, and `StructuredRuleEvaluator` throws rather than
     * evaluating them as constraints. They apply to every shape that can hold an answer — which is all
     * of them except {@see self::NoAnswer}, where there is no requiredness to condition.
     *
     * ⚠️ A `false` here means "offering this would build a form nobody can submit", not "this is
     * unusual". Every exclusion below is measured at `StructuredRuleEvaluator` and `ExpressionEvaluator`
     * rather than argued from taste — see the class docblock.
     */
    public function allows(ValidationRuleType $rule): bool
    {
        if ($this === self::NoAnswer) {
            return false;
        }

        return match ($rule) {
            ValidationRuleType::RequiredIf, ValidationRuleType::RequiredWith,
            ValidationRuleType::SkipIf, ValidationRuleType::SkipWith => true,

            ValidationRuleType::MinLength, ValidationRuleType::MaxLength,
            ValidationRuleType::Pattern => $this === self::Text,

            ValidationRuleType::MinValue, ValidationRuleType::MaxValue => $this === self::Number
                || $this === self::Duration
                || $this === self::Scale,

            ValidationRuleType::GreaterThanField, ValidationRuleType::LessThanField => $this === self::Number
                || $this === self::Duration,
        };
    }

    /**
     * Whether a CONDITION (`required_if` / `skip_if`) may compare a value of this shape with this operator.
     *
     * ⛔ THE ORDERED FOUR CARRY THE SAME FAIL-CLOSED DEFECT AS `min_value`, BY THE SAME ROUTE.
     * `StructuredRuleLowering::conditionForOperator()` lowers `gt`/`lt`/`gte`/`lte` to
     * `AstBuilders::comparison()`, and `ExpressionEvaluator` takes `Coercion::toNumber()` of both sides and
     * returns **false** on a NaN operand. So `required_if score > 'high'` is not a condition that sometimes
     * holds — it can never hold, and the field it guards silently never becomes required. Confined to the
     * three shapes whose stored answer really is a number. ⚠️ Since M134 the engines DO order two ISO dates
     * (`Temporal`), so for `Temporal` this refusal is now a scope choice rather than a guard: the structured
     * editor's value is free text nobody checks is a date. Lifting it is `R-af395416`'s structured half.
     *
     * ⚠️ `contains` IS SAFE WHERE IT IS OFFERED AND MEANINGLESS WHERE IT IS NOT.
     * `ExpressionEvaluator::evalMembershipFunction()` branches on the value: an array is a membership test,
     * a scalar is a substring test through `Coercion::toStr()`. That is exactly right for text, for a
     * multi-select's list and for a cascade's selection, and it is a trap everywhere else — substring-matching
     * a number or a date reads as a range test and is not one, and an object-valued answer would be searched
     * as whatever `toStr()` happens to make of it.
     *
     * ⚠️ `is_null` applies wherever there is an answer at all: `= ''` is an emptiness test, decided before
     * `equals()` looks at a list. `eq` and `neq` are NOT value-agnostic (Increment M134, `R-87160c81`, correcting
     * M112): `equals()` rule 3 makes an array or object on either side false — so against a multi-select, a cascade,
     * a file, a grid or a point, `eq` never holds and `neq` always does — rule 5 compares two numeric-like sides
     * through `toNumber()`, and rule 3b reads a yes/no by meaning. Offered only where the answer is one value.
     */
    public function allowsOperator(ComparisonOperator $operator): bool
    {
        if ($this === self::NoAnswer) {
            return false;
        }

        return match ($operator) {
            ComparisonOperator::IsNull => true,

            ComparisonOperator::Eq, ComparisonOperator::Neq => match ($this) {
                self::Text, self::Number, self::Temporal, self::Duration, self::Choice, self::Scale, self::Boolean => true,
                self::MultipleChoice, self::Hierarchy, self::Geo, self::Attachment, self::Grid => false,
            },

            ComparisonOperator::Gt, ComparisonOperator::Lt,
            ComparisonOperator::Gte, ComparisonOperator::Lte => $this === self::Number
                || $this === self::Duration
                || $this === self::Scale,

            ComparisonOperator::Contains => $this === self::Text
                || $this === self::Choice
                || $this === self::MultipleChoice
                || $this === self::Hierarchy,
        };
    }
}
