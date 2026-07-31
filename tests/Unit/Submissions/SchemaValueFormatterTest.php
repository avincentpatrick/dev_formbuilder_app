<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Services\Submissions\SchemaValueFormatter;

// `SchemaValueFormatter::displayValue()` — the value half of piping (Doc #26 §3.2) and the shared renderer
// behind the inbox detail view and the streamed export. Increment H6b's two corrections to it:
//
//   A7 — the scalar→string rule is PINNED rather than PHP's bare `(string)` cast, which JavaScript cannot
//        reproduce. Every expectation below is byte-identical to `display-value.ts`'s, and the golden
//        `formatting.json` vectors run BOTH engines against the same values.
//   A8 — an optional `$locale` resolves a choice option's `label_translations`, so a piped choice label
//        agrees with the sentence it is dropped into (§4's order, previously only half-honoured).
//
// The pre-H6b semantics this file also pins, because they were entirely uncovered and are easy to "tidy"
// into divergence: the STRICT three-way empty guard, the case-sensitive closed truthy list, the `'; '`
// join, and `??`-guards-null-only on an option label.
//
// EVERY `${` literal would need single quotes here (PHP 8.3 removed `${var}` interpolation) — this file
// has none, but the convention holds for anything added.

/** @return array<string, mixed> */
function svfChoiceConfig(): array
{
    return ['options' => [
        ['value' => 'ncr', 'label' => 'Metro Manila', 'label_translations' => ['fil' => 'Kalakhang Maynila']],
        ['value' => 'r4a', 'label' => 'Calabarzon'],
    ]];
}

describe('the pinned scalar→string rule (amendment A7)', function (): void {
    it('renders a native bool as PHP would cast it, not as JavaScript would', function (): void {
        // `ExpressionEvaluator::normalize()` passes a bool straight through, so a `calculated` field
        // holding `${age} >= 18` stores one. `String(true)` is "true" in JS — the whole reason A7 exists.
        $f = new SchemaValueFormatter;

        expect($f->displayValue(FieldType::Hidden, true, []))->toBe('1');
    });

    it('renders false as the empty string, and does NOT let the empty guard swallow it first', function (): void {
        // The guard is `=== null || === '' || === []`, so `false` falls THROUGH to the cast. A mirror
        // written as `if (!answer) return ''` gets the same output here for the wrong reason — and then
        // gets `0` wrong. Both are pinned.
        $f = new SchemaValueFormatter;

        expect($f->displayValue(FieldType::Hidden, false, []))->toBe('')
            ->and($f->displayValue(FieldType::Calculated, 0, []))->toBe('0')
            ->and($f->displayValue(FieldType::Calculated, 0.0, []))->toBe('0')
            ->and($f->displayValue(FieldType::ShortText, '0', []))->toBe('0');
    });

    it('renders a non-integral float in fixed notation, independent of the precision ini', function (): void {
        $f = new SchemaValueFormatter;

        expect($f->displayValue(FieldType::Calculated, 0.1 + 0.2, []))->toBe('0.3')
            ->and($f->displayValue(FieldType::Calculated, 1 / 3, []))->toBe('0.3333333333')
            ->and($f->displayValue(FieldType::Calculated, 2 / 3, []))->toBe('0.6666666667')
            ->and($f->displayValue(FieldType::Calculated, -1.5, []))->toBe('-1.5')
            ->and($f->displayValue(FieldType::Calculated, 3.5, []))->toBe('3.5');
    });

    it('renders an integral float without a fractional part and never in E-notation', function (): void {
        // The bare cast produced "1.0E+15" and "1.0E-5" here — both unreproducible in JS.
        $f = new SchemaValueFormatter;

        expect($f->displayValue(FieldType::Calculated, 3.0, []))->toBe('3')
            ->and($f->displayValue(FieldType::Calculated, 1e15, []))->toBe('1000000000000000')
            ->and($f->displayValue(FieldType::Calculated, 0.00001, []))->toBe('0.00001')
            ->and($f->displayValue(FieldType::Calculated, -0.0, []))->toBe('0');
    });

    it('renders a non-finite float as the empty string rather than "NAN"/"INF"', function (): void {
        $f = new SchemaValueFormatter;

        expect($f->displayValue(FieldType::Calculated, NAN, []))->toBe('')
            ->and($f->displayValue(FieldType::Calculated, INF, []))->toBe('');
    });

    it('leaves a STORED numeric string byte-identical, trailing zeros and all', function (): void {
        // A respondent's `decimal` persists as the submitted string; only a `calculated` value is a native
        // float. The same field type therefore holds two runtime types, which is why the vectors state
        // which one they pin.
        $f = new SchemaValueFormatter;

        expect($f->displayValue(FieldType::Decimal, '3.50', []))->toBe('3.50')
            ->and($f->displayValue(FieldType::Integer, '30', []))->toBe('30');
    });
});

describe('choice-label locale resolution (amendment A8)', function (): void {
    it('resolves an option label into the requested locale', function (): void {
        $f = new SchemaValueFormatter;

        expect($f->displayValue(FieldType::SingleSelect, 'ncr', svfChoiceConfig(), 'fil'))
            ->toBe('Kalakhang Maynila');
    });

    it('falls back to the base label when the locale has no variant', function (): void {
        $f = new SchemaValueFormatter;

        expect($f->displayValue(FieldType::SingleSelect, 'r4a', svfChoiceConfig(), 'fil'))->toBe('Calabarzon')
            ->and($f->displayValue(FieldType::SingleSelect, 'ncr', svfChoiceConfig(), 'es'))->toBe('Metro Manila');
    });

    it('treats a BLANK variant as no variant, mirroring resolveText()', function (): void {
        // The runtime's `resolveText()` falls back on `''` as well as on absent — Doc #26 §4 makes that the
        // normative resolver, so the two engines agree by construction. A `?? `-only mirror would render
        // the blank.
        $f = new SchemaValueFormatter;
        $config = ['options' => [['value' => 'ncr', 'label' => 'Metro Manila', 'label_translations' => ['fil' => '']]]];

        expect($f->displayValue(FieldType::SingleSelect, 'ncr', $config, 'fil'))->toBe('Metro Manila');
    });

    it('is byte-identical to the pre-H6b behaviour when no locale is passed', function (): void {
        $f = new SchemaValueFormatter;

        expect($f->displayValue(FieldType::SingleSelect, 'ncr', svfChoiceConfig()))->toBe('Metro Manila');
    });

    it('resolves every element of a multi-select and joins them with "; "', function (): void {
        $f = new SchemaValueFormatter;
        $config = ['options' => [
            ['value' => 'c', 'label' => 'Cough', 'label_translations' => ['fil' => 'Ubo']],
            ['value' => 'f', 'label' => 'Fever'],
        ]];

        expect($f->displayValue(FieldType::MultiSelect, ['c', 'f'], $config, 'fil'))->toBe('Ubo; Fever');
    });

    it('resolves a cascading_select, which is NOT a hasOptions() type', function (): void {
        // `SchemaValueFormatter` adds it with an explicit `||`; a mirror reproducing only `hasOptions()`
        // renders the raw codes.
        $f = new SchemaValueFormatter;
        $config = ['options' => [
            ['value' => 'ncr', 'label' => 'Metro Manila', 'level' => 'region', 'parent' => null, 'label_translations' => ['fil' => 'Kalakhang Maynila']],
            ['value' => 'manila', 'label' => 'Manila', 'level' => 'city', 'parent' => 'ncr'],
        ]];

        expect($f->displayValue(FieldType::CascadingSelect, ['ncr', 'manila'], $config, 'fil'))
            ->toBe('Kalakhang Maynila; Manila');
    });
});

describe('semantics H6b deliberately did NOT change', function (): void {
    it('keeps the truthy list closed and case-sensitive, so "Yes" is No', function (): void {
        $f = new SchemaValueFormatter;

        expect($f->displayValue(FieldType::YesNo, true, []))->toBe('Yes')
            ->and($f->displayValue(FieldType::YesNo, 1, []))->toBe('Yes')
            ->and($f->displayValue(FieldType::YesNo, 'yes', []))->toBe('Yes')
            ->and($f->displayValue(FieldType::YesNo, 'Yes', []))->toBe('No')
            ->and($f->displayValue(FieldType::YesNo, false, []))->toBe('No');
    });

    it('keeps the empty guard AHEAD of the yes_no branch, so unanswered is blank and not "No"', function (): void {
        $f = new SchemaValueFormatter;

        expect($f->displayValue(FieldType::YesNo, null, []))->toBe('');
    });

    it('lets an explicitly empty option label win, because the base fallback guards null only', function (): void {
        $f = new SchemaValueFormatter;
        $config = ['options' => [['value' => 'na', 'label' => '']]];

        expect($f->displayValue(FieldType::Dropdown, 'na', $config))->toBe('');
    });

    it('falls an unresolved choice code through to itself', function (): void {
        $f = new SchemaValueFormatter;

        expect($f->displayValue(FieldType::SingleSelect, 'zzz', svfChoiceConfig(), 'fil'))->toBe('zzz');
    });

    it('keeps a multi-select in submitted order, with duplicates and unknowns intact', function (): void {
        $f = new SchemaValueFormatter;
        $config = ['options' => [['value' => 'c', 'label' => 'Cough'], ['value' => 'f', 'label' => 'Fever']]];

        expect($f->displayValue(FieldType::MultiSelect, ['f', 'zzz', 'f'], $config))
            ->toBe('Fever; zzz; Fever');
    });
});
