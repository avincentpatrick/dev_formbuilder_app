<?php

declare(strict_types=1);

use App\Exceptions\Forms\FormException;
use App\Services\Forms\BlueprintValidator;

/*
|--------------------------------------------------------------------------
| BlueprintValidator (Increment G9a + the G9b rule_xor_chk hardening). No DB — the validator is a pure
| structural check. Focuses on validateField (the single-field entry point G9b's library insert calls) and
| the rule_xor invariant: a validation is EITHER a structured rule OR a raw expression, never both/neither.
|--------------------------------------------------------------------------
*/

it('accepts a field validation with exactly a rule_type', function (): void {
    (new BlueprintValidator)->validateField([
        'field_type' => 'short_text',
        'validations' => [['rule_type' => 'min_length', 'rule_value' => '3', 'expression' => null]],
    ]);

    expect(true)->toBeTrue(); // reached ⇒ no throw
});

it('accepts a field validation with exactly an expression', function (): void {
    (new BlueprintValidator)->validateField([
        'field_type' => 'short_text',
        'validations' => [['rule_type' => null, 'expression' => '${age} > 0']],
    ]);

    expect(true)->toBeTrue();
});

it('rejects a validation that sets BOTH rule_type and expression', function (): void {
    expect(fn () => (new BlueprintValidator)->validateField([
        'field_type' => 'short_text',
        'validations' => [['rule_type' => 'min_length', 'expression' => '${age} > 0']],
    ]))->toThrow(FormException::class);
});

it('rejects a validation that sets NEITHER rule_type nor expression', function (): void {
    expect(fn () => (new BlueprintValidator)->validateField([
        'field_type' => 'short_text',
        'validations' => [['rule_value' => '3']],
    ]))->toThrow(FormException::class);
});

it('rejects an unknown field_type on a single field', function (): void {
    expect(fn () => (new BlueprintValidator)->validateField(['field_type' => 'not_a_type', 'validations' => []]))
        ->toThrow(FormException::class);
});

it('tolerates an unresolved related_field_key on a single field (materializeField nulls it)', function (): void {
    (new BlueprintValidator)->validateField([
        'field_type' => 'short_text',
        'validations' => [['rule_type' => 'greater_than_field', 'related_field_key' => 'nonexistent']],
    ]);

    expect(true)->toBeTrue();
});

it('enforces the rule_xor invariant on the whole-form validate() path too', function (): void {
    expect(fn () => (new BlueprintValidator)->validate([
        'sections' => [],
        'fields' => [[
            'key' => 'q', 'section_key' => null, 'field_type' => 'short_text',
            'validations' => [['rule_type' => 'min_length', 'expression' => 'both set']],
        ]],
    ]))->toThrow(FormException::class);
});
