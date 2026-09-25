<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\FieldType;
use App\Enums\ValidationRuleType;
use App\Enums\ValueShape;

// The M112 value-shape partition, locked type by type (no database, no container — tests/Pest.php binds
// TestCase to Feature only). The totality test below is what makes a 32nd FieldType case impossible to
// add without a decision, and the named cases below it are what make the two MEASURED splits impossible
// to collapse by accident.

/**
 * The partition, transcribed independently of the enum — the point is that the two are written
 * separately and must agree. Twelve shapes over thirty-one types.
 *
 * @return array<string, list<string>>
 */
function valueShapeTable(): array
{
    return [
        'text' => ['short_text', 'long_text', 'email', 'phone', 'url', 'hidden'],
        'number' => ['integer', 'decimal', 'calculated'],
        'temporal' => ['date', 'time', 'datetime'],
        'duration' => ['duration'],
        'choice' => ['single_select', 'multi_select', 'dropdown'],
        'scale' => ['likert_scale'],
        'boolean' => ['yes_no'],
        'hierarchy' => ['cascading_select'],
        'geo' => ['geopoint', 'geotrace', 'geoshape'],
        'attachment' => ['file_upload', 'image_capture', 'audio_capture', 'video_capture', 'signature'],
        'grid' => ['matrix', 'likert_matrix'],
        'no_answer' => ['note', 'page_break'],
    ];
}

/** @return list<string> the backing values of every type mapping to the given shape, in catalog order */
function typesWithShape(ValueShape $shape): array
{
    return array_values(array_map(
        static fn (FieldType $t): string => $t->value,
        array_filter(FieldType::cases(), static fn (FieldType $t): bool => ValueShape::for($t) === $shape),
    ));
}

it('classifies every field type exactly as the transcribed table says', function (): void {
    foreach (valueShapeTable() as $shapeValue => $expected) {
        $shape = ValueShape::from($shapeValue);

        expect(typesWithShape($shape))
            ->toEqualCanonicalizing($expected, "ValueShape::{$shapeValue} membership has drifted from the table");
    }
});

it('partitions all thirty-one field types exactly once, with no shape left empty', function (): void {
    $table = valueShapeTable();

    // Anti-vacuity, both ways: the table must cover the enum, and the enum must not have shrunk under it.
    expect(FieldType::cases())->toHaveCount(31);
    expect(ValueShape::cases())->toHaveCount(count($table));
    expect(array_sum(array_map('count', $table)))->toBe(31);

    // Every shape is reachable — a case nothing maps to is a case that classifies nothing.
    foreach (ValueShape::cases() as $shape) {
        expect(typesWithShape($shape))->not->toBeEmpty("ValueShape::{$shape->value} classifies no field type");
    }

    // And the union is the whole catalog, with no type counted twice.
    $flattened = array_merge(...array_values($table));
    expect($flattened)->toHaveCount(31)
        ->and(array_unique($flattened))->toHaveCount(31)
        ->and($flattened)->toEqualCanonicalizing(array_map(
            static fn (FieldType $t): string => $t->value,
            FieldType::cases(),
        ));
});

// ── The two measured splits, each pinned by name ────────────────────────────────────────────────

it('keeps yes_no OUT of Choice, because folding it in makes every yes/no field unpublishable', function (): void {
    // `yes_no`'s two options are fixed and stored nowhere, so a gate asserting that Choice fields resolve
    // an author option list would refuse every yes/no field in the product. The totality assertion above
    // passes either way, which is exactly why this one is written separately.
    expect(ValueShape::for(FieldType::YesNo))->toBe(ValueShape::Boolean)
        ->and(ValueShape::for(FieldType::YesNo)->carriesOptionList())->toBeFalse();
});

it('separates duration from the other temporal types, because only duration stores a number', function (): void {
    // XlsformTypeMap maps `duration` to `decimal`; Coercion::NUMERIC_RE does not match `2026-01-15`.
    expect(ValueShape::for(FieldType::Duration))->toBe(ValueShape::Duration)
        ->and(ValueShape::for(FieldType::Date))->toBe(ValueShape::Temporal);
});

// ── carriesOptionList(): the total replacement for FieldType::hasOptions() ──────────────────────

it('agrees with FieldType::hasOptions() on every one of the thirty-one types', function (): void {
    // The membership must be IDENTICAL — what differs is that carriesOptionList() has no default arm.
    // If these ever disagree, one of them absorbed a new type silently, which is the whole defect.
    foreach (FieldType::cases() as $type) {
        expect(ValueShape::for($type)->carriesOptionList())->toBe(
            $type->hasOptions(),
            "carriesOptionList() and hasOptions() disagree about {$type->value}",
        );
    }
});

it('names the four option-bearing types, so the set cannot silently empty', function (): void {
    $withOptions = array_values(array_map(
        static fn (FieldType $t): string => $t->value,
        array_filter(FieldType::cases(), static fn (FieldType $t): bool => ValueShape::for($t)->carriesOptionList()),
    ));

    expect($withOptions)->toEqualCanonicalizing(['single_select', 'multi_select', 'dropdown', 'likert_scale']);
});

// ── allows(): what may be asserted about a value of each shape ──────────────────────────────────

it('refuses min_value and greater_than_field on a temporal shape, because both fail CLOSED', function (): void {
    // StructuredRuleEvaluator's MinValue arm is `isEmpty($answer) || (isNumericLike($answer) && …)` and
    // ExpressionEvaluator's ordered comparison returns false on a NaN operand. A date is not numeric-like,
    // so either rule makes EVERY non-empty answer invalid — the field becomes unanswerable.
    expect(ValueShape::Temporal->allows(ValidationRuleType::MinValue))->toBeFalse()
        ->and(ValueShape::Temporal->allows(ValidationRuleType::MaxValue))->toBeFalse()
        ->and(ValueShape::Temporal->allows(ValidationRuleType::GreaterThanField))->toBeFalse()
        ->and(ValueShape::Temporal->allows(ValidationRuleType::LessThanField))->toBeFalse();
});

it('allows min_value on the three shapes whose stored answer really is a number', function (): void {
    // The paired positive, so the refusal above cannot pass by allows() returning false for everything.
    expect(ValueShape::Number->allows(ValidationRuleType::MinValue))->toBeTrue()
        ->and(ValueShape::Duration->allows(ValidationRuleType::MinValue))->toBeTrue()
        ->and(ValueShape::Scale->allows(ValidationRuleType::MinValue))->toBeTrue()
        ->and(ValueShape::Choice->allows(ValidationRuleType::MinValue))->toBeFalse();
});

it('confines the text rules to the text shape', function (): void {
    foreach ([ValidationRuleType::MinLength, ValidationRuleType::MaxLength, ValidationRuleType::Pattern] as $rule) {
        expect(ValueShape::Text->allows($rule))->toBeTrue("Text should allow {$rule->value}");

        foreach (ValueShape::cases() as $shape) {
            if ($shape === ValueShape::Text) {
                continue;
            }

            expect($shape->allows($rule))->toBeFalse("{$shape->value} should not allow {$rule->value}");
        }
    }
});

it('allows the conditional family on every shape that can hold an answer, and on none that cannot', function (): void {
    $conditionals = [
        ValidationRuleType::RequiredIf,
        ValidationRuleType::RequiredWith,
        ValidationRuleType::SkipIf,
        ValidationRuleType::SkipWith,
    ];

    foreach (ValueShape::cases() as $shape) {
        foreach ($conditionals as $rule) {
            expect($shape->allows($rule))->toBe(
                $shape !== ValueShape::NoAnswer,
                "{$shape->value} disagrees about {$rule->value}",
            );
        }
    }
});

it('offers a note or page break nothing at all', function (): void {
    // The Validation tab should not render for these, and this is the server-side half of that.
    foreach (ValidationRuleType::cases() as $rule) {
        expect(ValueShape::NoAnswer->allows($rule))->toBeFalse("NoAnswer should not allow {$rule->value}");
    }
});

it('leaves no rule type unanswered by any shape', function (): void {
    // Anti-vacuity over the OTHER axis: every one of the eleven rule types must be allowed by at least
    // one shape and refused by at least one, or the table has a column that decides nothing.
    expect(ValidationRuleType::cases())->toHaveCount(11);

    foreach (ValidationRuleType::cases() as $rule) {
        $allowed = array_filter(ValueShape::cases(), static fn (ValueShape $s): bool => $s->allows($rule));
        $refused = array_filter(ValueShape::cases(), static fn (ValueShape $s): bool => ! $s->allows($rule));

        expect($allowed)->not->toBeEmpty("no shape allows {$rule->value}");
        expect($refused)->not->toBeEmpty("every shape allows {$rule->value} — the column decides nothing");
    }
});

// ── allowsOperator(): which comparisons a CONDITION may make against each shape ─────────────────

it('refuses the ordered operators on a temporal shape, because a condition would never hold', function (): void {
    // StructuredRuleLowering lowers gt/lt/gte/lte to AstBuilders::comparison(), and ExpressionEvaluator
    // returns false on a NaN operand — so `required_if visit_date > '2026-01-01'` is not a condition that
    // sometimes holds, it is one that can NEVER hold, and the field it guards never becomes required.
    foreach ([ComparisonOperator::Gt, ComparisonOperator::Lt, ComparisonOperator::Gte, ComparisonOperator::Lte] as $op) {
        expect(ValueShape::Temporal->allowsOperator($op))->toBeFalse("Temporal should not allow {$op->value}");
        expect(ValueShape::Number->allowsOperator($op))->toBeTrue("Number should allow {$op->value}");
    }
});

it('allows equality and blankness everywhere there is an answer at all', function (): void {
    foreach ([ComparisonOperator::Eq, ComparisonOperator::Neq, ComparisonOperator::IsNull] as $op) {
        foreach (ValueShape::cases() as $shape) {
            expect($shape->allowsOperator($op))->toBe(
                $shape !== ValueShape::NoAnswer,
                "{$shape->value} disagrees about {$op->value}",
            );
        }
    }
});

it('offers contains only where a value is a list or a string', function (): void {
    // evalMembershipFunction() branches: an array is a membership test, a scalar a substring test. That is
    // right for text, a multi-select's list and a cascade's selection — and a trap for a number or a date,
    // where a substring match reads as a range test and is not one.
    expect(ValueShape::Text->allowsOperator(ComparisonOperator::Contains))->toBeTrue()
        ->and(ValueShape::Choice->allowsOperator(ComparisonOperator::Contains))->toBeTrue()
        ->and(ValueShape::Hierarchy->allowsOperator(ComparisonOperator::Contains))->toBeTrue()
        ->and(ValueShape::Number->allowsOperator(ComparisonOperator::Contains))->toBeFalse()
        ->and(ValueShape::Temporal->allowsOperator(ComparisonOperator::Contains))->toBeFalse()
        ->and(ValueShape::Grid->allowsOperator(ComparisonOperator::Contains))->toBeFalse();
});

it('offers a note or page break no operator at all', function (): void {
    foreach (ComparisonOperator::cases() as $op) {
        expect(ValueShape::NoAnswer->allowsOperator($op))->toBeFalse("NoAnswer should not allow {$op->value}");
    }
});

it('leaves no operator unanswered by any shape', function (): void {
    // Anti-vacuity over the operator axis: every one of the eight must be allowed somewhere and refused
    // somewhere, or the column decides nothing.
    expect(ComparisonOperator::cases())->toHaveCount(8);

    foreach (ComparisonOperator::cases() as $op) {
        $allowed = array_filter(ValueShape::cases(), static fn (ValueShape $s): bool => $s->allowsOperator($op));
        $refused = array_filter(ValueShape::cases(), static fn (ValueShape $s): bool => ! $s->allowsOperator($op));

        expect($allowed)->not->toBeEmpty("no shape allows {$op->value}");
        expect($refused)->not->toBeEmpty("every shape allows {$op->value} — the column decides nothing");
    }
});
