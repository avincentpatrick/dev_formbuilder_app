<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Expressions\StructuredRuleLowering;
use App\Services\Forms\BuilderPresenter;
use App\Services\Validation\SemanticValidator;

/**
 * Structured validation rule kinds (data-dictionary §6), mirroring legacy's 11-row `rule_types`
 * lookup. Mutually exclusive with a free-text `expression` on the same row (a DB CHECK enforces
 * exactly one of `rule_type` / `expression`).
 */
enum ValidationRuleType: string
{
    case MinValue = 'min_value';
    case MaxValue = 'max_value';
    case MinLength = 'min_length';
    case MaxLength = 'max_length';
    case Pattern = 'pattern';
    case RequiredIf = 'required_if';
    case RequiredWith = 'required_with';
    case SkipIf = 'skip_if';
    case SkipWith = 'skip_with';
    case GreaterThanField = 'greater_than_field';
    case LessThanField = 'less_than_field';

    /**
     * How this rule reads to an author (Increment M115). Replaces `BuilderPresenter`'s `humanize()` —
     * `ucfirst(str_replace('_', ' ', …))` over the enum value — for this list.
     *
     * ⛔ A `match` WITH NO `default`, like every other registry in this directory: a twelfth rule type
     * must be a PHPStan level-8 error rather than a row that renders its own database value at an author.
     */
    public function label(): string
    {
        return match ($this) {
            self::MinValue => 'Minimum value',
            self::MaxValue => 'Maximum value',
            self::MinLength => 'Minimum length',
            self::MaxLength => 'Maximum length',
            self::Pattern => 'Must match a pattern',
            self::RequiredIf => 'Required when a condition holds',
            self::RequiredWith => 'Required with another question',
            self::SkipIf => 'Skipped when a condition holds',
            self::SkipWith => 'Skipped with another question',
            self::GreaterThanField => 'Greater than another question',
            self::LessThanField => 'Less than another question',
        };
    }

    /**
     * Whether the `operator` column is READ for a rule of this kind (Increment M115).
     *
     * ⛔ MEASURED AT THE LOWERINGS, NOT REASONED FROM THE COLUMN'S EXISTENCE — and this is the fact the
     * builder was missing. {@see StructuredRuleLowering::lowerCondition()} is a
     * `match` over exactly these four cases and throws `unlowerableRuleType` for every other rule type;
     * `resources/public-runtime/engine/lowering.ts` is its twin. So for the other seven the column is
     * never read at all, and the builder offering an operator beside `pattern` or `min_length` was
     * offering a control with no effect.
     *
     * ⚠️ AND THE OPERATOR IT NAMES COMPARES THE *RELATED* FIELD, NEVER THE OWNING ONE. That is why
     * {@see ValueShape::allowsOperator()} must be asked about the field named by
     * {@see takesRelatedField()}, and asking it about the rule's owner would filter the wrong list.
     * Pinned behaviourally against the lowering by `tests/Unit/Expressions/ConditionalLoweringPinTest.php`
     * rather than restated, because a third copy of a control-flow fact is a third thing to drift.
     */
    public function takesOperator(): bool
    {
        return match ($this) {
            self::RequiredIf, self::RequiredWith, self::SkipIf, self::SkipWith => true,
            self::MinValue, self::MaxValue, self::MinLength, self::MaxLength, self::Pattern,
            self::GreaterThanField, self::LessThanField => false,
        };
    }

    /**
     * Whether a rule of this kind names a SECOND field (Increment M115) — the `related_form_field_id`
     * column, surfaced to the builder as `related_field_key`.
     *
     * ⛔ THE BUILDER KNEW TWO OF THESE SIX AND THAT WAS THE DEFECT. `ValidationEditor.vue` carried a
     * client-side literal `FIELD_COMPARISON = new Set(['greater_than_field', 'less_than_field'])` and
     * rendered the compared-field input for those two only — so the four conditional rules, which
     * `lowerCondition()` cannot lower without a related key, had no control to name one and were
     * **uncompletable in that editor**. This method replaces that literal rather than joining it.
     */
    public function takesRelatedField(): bool
    {
        return match ($this) {
            self::RequiredIf, self::RequiredWith, self::SkipIf, self::SkipWith,
            self::GreaterThanField, self::LessThanField => true,
            self::MinValue, self::MaxValue, self::MinLength, self::MaxLength, self::Pattern => false,
        };
    }

    /**
     * Whether a rule of this kind is still evaluable with NO operator — and what the absence means
     * (Increment M115). Only meaningful where {@see takesOperator()} is true.
     *
     * ⛔ AN EMPTY OPERATOR IS NOT "UNSET" HERE, IT IS A DIFFERENT CONDITION. `lowerCondition()` reads
     * `required_with` / `skip_with` with a null operator as `isNotNull(relatedKey)` — *"when that
     * question is answered at all"* — which is a legitimate and probably the commonest authoring
     * choice. For `required_if` / `skip_if` the same null reaches `conditionForOperator()`'s default arm
     * and **throws**, so a row saved that way publishes clean and then fails every submission.
     *
     * ⚠️ THIS IS A THIRD FACT AND THE PLAN FOR THIS INCREMENT NAMED ONLY TWO. It exists because the
     * editor cannot be honest without it: the operator control must offer an explicit "is answered"
     * choice where the absence has that meaning, and must not offer an empty one where the absence is
     * a broken rule.
     */
    public function operatorMayBeEmpty(): bool
    {
        return match ($this) {
            self::RequiredWith, self::SkipWith => true,
            self::RequiredIf, self::SkipIf,
            self::MinValue, self::MaxValue, self::MinLength, self::MaxLength, self::Pattern,
            self::GreaterThanField, self::LessThanField => false,
        };
    }

    /**
     * Whether a rule of this kind is what makes `RequiredMode::Conditional` mean something (Increment M116)
     * — that is, whether it can ever turn an unanswered field into a required one.
     *
     * ⛔ THIS MIRRORS ONE BUCKET OF {@see SemanticValidator::family()} AND IS
     * DELIBERATELY NOT THAT METHOD. `family()` sorts a row into `required` / `skip` / `constraint`, and its
     * third arm is a `default` — composing a gate or a client payload out of a `default` arm means any rule
     * type added later is silently classified rather than refused at the `match`. This one enumerates all
     * eleven cases, so adding a twelfth is a compile error until somebody decides which side it belongs on.
     * `family()` also classifies expression rows, which have no `rule_type` at all.
     *
     * ⚠️ IT EXISTS TO BE TRANSMITTED, NOT MIRRORED. The builder needs this classification to know which
     * rows belong under the Basics tab's *"Required when…"* reveal, and a client-side literal
     * `['required_if', 'required_with']` is exactly the defect `M115` was spent removing from
     * `ValidationEditor.vue`. It rides to the client as `governs_requiredness` through
     * {@see BuilderPresenter::enums()} and is censused by
     * `tests/Feature/Forms/BuilderEnumsPayloadTest.php`.
     *
     * ⚠️ THE SKIP FAMILY IS NOT HERE, AND THAT IS THE DISTINCTION WORTH STATING. `skip_if` / `skip_with`
     * make a field IRRELEVANT rather than required; they are read by `settleRelevance()`, not by
     * `requiredState()`. A field marked conditionally required whose only rule is a `skip_if` is still a
     * field nothing can ever require — which is why the publish gate asks this question rather than
     * "does this field own any validation row at all".
     */
    public function governsRequiredness(): bool
    {
        return match ($this) {
            self::RequiredIf, self::RequiredWith => true,
            self::SkipIf, self::SkipWith,
            self::MinValue, self::MaxValue, self::MinLength, self::MaxLength, self::Pattern,
            self::GreaterThanField, self::LessThanField => false,
        };
    }

    /**
     * The comparison this rule makes against its RELATED question's answer, or null when it makes none the
     * publish gate can judge (Increment M123, `R-2c172882`).
     *
     * ⛔ IT FOLLOWS THE LOWERING, NOT THE COLUMN — {@see StructuredRuleLowering::lowerCondition()} is the
     * authority for what each row actually compares:
     *   - The four conditional kinds compare with their stored operator. A NULL operator on `required_with` /
     *     `skip_with` is not "unset": it lowers to `isNotNull(related)`, so the comparison it makes is `IsNull`'s
     *     and a note — which is never answered — makes it a constant. A null on `required_if` / `skip_if` stays
     *     null here, because that row is the missing-operator refusal's and reporting it twice helps nobody.
     *   - `greater_than_field` / `less_than_field` ORDER the two answers and never read the operator column at
     *     all, so a stray stored operator must not change the verdict.
     *   - The other five name no second question.
     *
     * ⚠️ IT LIVES HERE SO THE GATE NEEDS NO NEW `use` LINE. `StructuralValidationGate.php` is cited by line from
     * an ADR (zero tolerance), and an import above those anchors would silently retarget them onto other
     * prose — the citation linter checks only that a cited line is alive, never that it still says the thing.
     */
    public function relatedComparison(?ComparisonOperator $stored): ?ComparisonOperator
    {
        return match ($this) {
            self::RequiredIf, self::SkipIf, self::RequiredWith, self::SkipWith => $stored
                ?? ($this->operatorMayBeEmpty() ? ComparisonOperator::IsNull : null),
            self::GreaterThanField => ComparisonOperator::Gt,
            self::LessThanField => ComparisonOperator::Lt,
            self::MinValue, self::MaxValue, self::MinLength, self::MaxLength, self::Pattern => null,
        };
    }
}
