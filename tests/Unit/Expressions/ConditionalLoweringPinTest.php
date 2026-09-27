<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\ValidationRuleType;
use App\Exceptions\Expressions\ExpressionEvaluationException;
use App\Models\FormFieldValidation;
use App\Services\Expressions\StructuredRuleLowering;

/*
|--------------------------------------------------------------------------
| The three facts M115 added to ValidationRuleType, pinned to the LOWERING that owns them.
|--------------------------------------------------------------------------
| `takesOperator()`, `takesRelatedField()` and `operatorMayBeEmpty()` were added so the builder could stop
| guessing which rules use which columns — it previously offered an operator beside `pattern` and
| `min_length`, where the column is never read, and offered the four conditional rules no way to name the
| related field they cannot be lowered without.
|
| ⛔ WHY THESE ARE PINNED BEHAVIOURALLY AND NOT BY A TABLE. The truth lives in control flow —
| `StructuredRuleLowering::lower()` and `::lowerCondition()`, each a `match` that throws for every arm it
| does not name — and a table restating control flow is a third copy that drifts. This is the distinction
| `tests/Unit/Forms/StepProjectionTest.php` and `resources/public-runtime/__tests__/steps.test.ts` both
| record: parse DATA, but pin BEHAVIOUR. So every case below drives the real lowering and asks the enum to
| agree with what happened, which means a future arm added to either `match` reddens here until the enum
| is told about it.
|
| ⚠️ ORDER MATTERS INSIDE `lowerCondition()` AND IT SHAPES WHAT CAN BE PROVED. It calls
| `relatedKeyOrThrow()` BEFORE its `match`, so with no related field EVERY rule type fails there — which is
| why the related-field fact is pinned through `lower()` (whose `match` comes first) plus a necessity case,
| rather than by comparing exception slugs on that entry point.
*/

/**
 * form_field id → key, as a version's field set resolves.
 *
 * @return array<string, string>
 */
function pinKeyMap(): array
{
    return ['fowner' => 'owner', 'frelated' => 'related'];
}

function pinRow(ValidationRuleType $type, ?ComparisonOperator $operator, ?string $relatedId): FormFieldValidation
{
    return makeValidationRow([
        'id' => 'pin-row',
        'rule_type' => $type,
        'form_field_id' => 'fowner',
        'related_form_field_id' => $relatedId,
        'operator' => $operator,
        'rule_value' => '18',
    ]);
}

/** The slug of the refusal, or null when the lowering succeeded. */
function pinLower(string $method, ValidationRuleType $type, ?ComparisonOperator $operator, ?string $relatedId = 'frelated'): ?string
{
    $lowering = new StructuredRuleLowering;

    try {
        $lowering->{$method}(pinRow($type, $operator, $relatedId), pinKeyMap());
    } catch (ExpressionEvaluationException $e) {
        return $e->slug();
    }

    return null;
}

it('lowers a condition for exactly the rule types takesOperator() names', function (): void {
    foreach (ValidationRuleType::cases() as $type) {
        $slug = pinLower('lowerCondition', $type, ComparisonOperator::Eq);

        expect($slug === null)->toBe(
            $type->takesOperator(),
            "lowerCondition() and takesOperator() disagree about {$type->value}"
            .($slug === null ? ' — it lowered and the enum says it takes no operator' : " — it refused with {$slug} and the enum says it does")
        );
    }
});

it('lowers a field comparison for exactly the two rule types that name a field without taking an operator', function (): void {
    // The second entry point, and the derivation is the assertion: a field-comparison rule is precisely one
    // that NAMES a field and READS no operator. Stating it this way pins both methods at once and cannot be
    // satisfied by a lucky pair.
    foreach (ValidationRuleType::cases() as $type) {
        $slug = pinLower('lower', $type, null);

        expect($slug === null)->toBe(
            $type->takesRelatedField() && ! $type->takesOperator(),
            "lower() disagrees with the enum about {$type->value}"
        );
    }
});

it('reads a missing operator as a condition of its own for exactly the rule types operatorMayBeEmpty() names', function (): void {
    // ⛔ THE CASE THAT MATTERS TO AN AUTHOR. `required_with` with no operator is the commonest thing anyone
    // would author — "required when that question is answered" — and lowers to `isNotNull(related)`. The
    // same null on `required_if` reaches `conditionForOperator()`'s default arm and THROWS, so a row saved
    // that way publishes clean and then fails every submission. The editor has to know the difference.
    foreach (ValidationRuleType::cases() as $type) {
        if (! $type->takesOperator()) {
            continue;
        }

        $slug = pinLower('lowerCondition', $type, null);

        expect($slug === null)->toBe(
            $type->operatorMayBeEmpty(),
            "an empty operator on {$type->value} behaves differently from what operatorMayBeEmpty() claims"
        );
    }
});

it('cannot lower any rule that names a related field without one, and cannot lower the rest at all', function (): void {
    // Necessity for the six, impossibility for the five — which together are `takesRelatedField()`'s
    // membership. A rule no lowering will touch under any input cannot be said to need a related field.
    foreach (ValidationRuleType::cases() as $type) {
        if ($type->takesRelatedField()) {
            $method = $type->takesOperator() ? 'lowerCondition' : 'lower';

            expect(pinLower($method, $type, ComparisonOperator::Eq, null))
                ->toBe('missing_related_field', "{$type->value} lowered without the related field it names");
            expect(pinLower($method, $type, ComparisonOperator::Eq))
                ->toBeNull("{$type->value} would not lower even WITH its related field, so the pin above proves nothing");

            continue;
        }

        expect(pinLower('lower', $type, ComparisonOperator::Eq))->toBe('unlowerable_rule_type');
        expect(pinLower('lowerCondition', $type, ComparisonOperator::Eq))->toBe('unlowerable_rule_type');
    }
});

it('pins the memberships as counts, so a match arm deleted on both sides cannot pass quietly', function (): void {
    // ⛔ ANTI-VACUITY. Every case above compares the lowering against the enum, so deleting an arm from BOTH
    // would keep them agreeing. These three numbers are the floor: four conditionals, six field-naming
    // rules, two that read an absent operator as a condition.
    $count = fn (callable $p): int => count(array_filter(ValidationRuleType::cases(), $p));

    expect($count(fn (ValidationRuleType $t): bool => $t->takesOperator()))->toBe(4)
        ->and($count(fn (ValidationRuleType $t): bool => $t->takesRelatedField()))->toBe(6)
        ->and($count(fn (ValidationRuleType $t): bool => $t->operatorMayBeEmpty()))->toBe(2)
        ->and(ValidationRuleType::cases())->toHaveCount(11);
});
