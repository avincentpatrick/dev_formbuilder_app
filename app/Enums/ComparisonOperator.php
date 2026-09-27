<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Comparison operators for comparison-style validation rules (data-dictionary §6), mirroring legacy's
 * 6-row `rule_formulas` lookup, plus the `>=`/`<=` operators added with grammar v2.0 (Increment G3). Only
 * meaningful for comparison `ValidationRuleType`s and the expression grammar's comparison nodes.
 */
enum ComparisonOperator: string
{
    case Gt = 'gt';
    case Lt = 'lt';
    case Gte = 'gte';
    case Lte = 'lte';
    case Eq = 'eq';
    case Neq = 'neq';
    case IsNull = 'is_null';
    case Contains = 'contains';

    /**
     * How this operator reads in a RULE ROW, where the three controls are read as a phrase:
     * "Maximum value · at most (≤) · 100" (Increment M115, answering `D59` option C).
     *
     * ⛔ THE SYMBOL IS PART OF THE STRING, DELIBERATELY. `D59`'s answer is that no caller may render
     * the symbol without the words or pair them wrongly — which is unconstructible if there is only
     * ever one string. A second `symbol()` method would be a second thing to keep in step, and the
     * builder previously shipped `Gt` / `Lte` / `Neq` precisely because the label was derived rather
     * than written.
     *
     * ⛔ AND IT IS A `match` WITH NO `default`, like every other registry in this directory: a ninth
     * operator must be a PHPStan level-8 error here, not a case that silently renders its own
     * enum value at an author.
     */
    public function label(): string
    {
        return match ($this) {
            self::Gt => 'greater than (>)',
            self::Lt => 'less than (<)',
            self::Gte => 'at least (≥)',
            self::Lte => 'at most (≤)',
            self::Eq => 'equals (=)',
            self::Neq => 'does not equal (≠)',
            self::IsNull => 'is blank',
            self::Contains => 'contains',
        };
    }

    /**
     * How this operator reads INSIDE A SENTENCE: "Age is at least 18" (Increment M115, `D59` option C).
     *
     * ⚠️ TWO RENDERINGS AND NOT TWO ENUMS, because the same operator genuinely reads differently in the
     * two places: "Maximum value / at most / 100" is right in a rule row and wrong in a sentence, and
     * "Age is at most 100" is right in a sentence and wrong in a row.
     *
     * ⛔ THE SIX ORDERED AND EQUALITY VALUES ARE ALREADY RENDERED THIS WAY ON THE CLIENT, AND THIS
     * METHOD MUST NOT DISAGREE WITH THEM. `ConditionRow.vue`'s `COMPARATOR_LABELS` and
     * `condition-describer.ts`'s `COMPARATORS` are two byte-identical copies of exactly these strings,
     * both shipped before this method existed, both pinned by `condition-describer.test.ts`'s prose
     * assertions. `tests/Unit/Forms/ConditionLabelMirrorDriftTest.php` asserts all three agree.
     *
     * ⚠️ WHAT THIS METHOD DOES *NOT* COVER, AND WHY THAT IS NOT A GAP. The condition editor's row
     * vocabulary is LARGER than this enum: it also offers `not_blank` and `excludes`, which have no
     * `ComparisonOperator` case at all (the expression grammar negates through `not(...)`, so they are
     * AST shapes rather than stored operators), and a second COUNT phrasing ("has at least") for a
     * `count()` subject. `D59`'s recorded answer says `ConditionRow.vue` "stops owning its own set";
     * measured, it cannot, because eight of its ten entries have a case here and two do not. Sourcing
     * the shared six from PHP and leaving two client-side would split ONE vocabulary across two
     * sources — strictly worse than the drift gate that now pins them.
     */
    public function sentenceLabel(): string
    {
        return match ($this) {
            self::Gt => 'is more than',
            self::Lt => 'is less than',
            self::Gte => 'is at least',
            self::Lte => 'is at most',
            self::Eq => 'is',
            self::Neq => 'is not',
            self::IsNull => 'is blank',
            self::Contains => 'includes',
        };
    }
}
