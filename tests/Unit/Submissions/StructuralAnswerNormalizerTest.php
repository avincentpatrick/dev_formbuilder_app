<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\FormField;
use App\Models\FormSection;
use App\Services\Submissions\StructuralAnswerNormalizer;

/**
 * Stage-1 structural normalisation, driven purely over UNSAVED FormField models (casts apply without a
 * database — the F3 SemanticValidatorTest pattern). No container, no Postgres.
 */

/**
 * @param  array<int, FormField>  $fields
 * @param  array<string, mixed>  $answers
 * @param  array<int, FormSection>  $sections
 * @return array<string, mixed>
 */
function normalizeAnswers(array $fields, array $answers, array $sections = []): array
{
    return (new StructuralAnswerNormalizer)->normalize(collect($fields), collect($sections), $answers);
}

function normalizerField(string $key, FieldType $type): FormField
{
    return makeSchemaField(['key' => $key, 'field_type' => $type]);
}

/** A repeatable section + a member field belonging to it (member carries the section id). */
function repeatSection(string $key): FormSection
{
    return makeSchemaSection(['id' => $key, 'key' => $key, 'is_repeatable' => true]);
}

function repeatMember(string $key, string $sectionId, FieldType $type = FieldType::ShortText): FormField
{
    return makeSchemaField(['key' => $key, 'field_type' => $type, 'form_section_id' => $sectionId]);
}

it('rejects an unknown key with a structural error', function (): void {
    $fields = [normalizerField('name', FieldType::ShortText)];

    try {
        normalizeAnswers($fields, ['name' => 'Ada', 'ghost' => 'x']);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->stage())->toBe('structural')
            ->and($e->fieldErrors())->toBe([
                ['field' => 'ghost', 'rule' => 'unknown_field', 'message' => 'Unknown field: ghost.'],
            ]);
    }
});

it('keeps text as a string and casts scalars to string', function (): void {
    $fields = [normalizerField('name', FieldType::ShortText), normalizerField('code', FieldType::ShortText)];

    expect(normalizeAnswers($fields, ['name' => 'Ada', 'code' => 42]))
        ->toBe(['name' => 'Ada', 'code' => '42']);
});

it('rejects an array in a scalar field as a type mismatch', function (): void {
    $fields = [normalizerField('name', FieldType::ShortText)];

    try {
        normalizeAnswers($fields, ['name' => ['a', 'b']]);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['rule'])->toBe('expected_scalar');
    }
});

it('accepts numeric-like values for numeric fields and rejects non-numeric', function (): void {
    $fields = [normalizerField('age', FieldType::Integer), normalizerField('rate', FieldType::Decimal)];

    expect(normalizeAnswers($fields, ['age' => '30', 'rate' => '3.14']))
        ->toBe(['age' => '30', 'rate' => '3.14']);

    try {
        normalizeAnswers([normalizerField('age', FieldType::Integer)], ['age' => 'thirty']);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['rule'])->toBe('not_a_number');
    }
});

it('normalises yes/no to a real boolean including the string "no"', function (): void {
    $fields = [
        normalizerField('a', FieldType::YesNo), normalizerField('b', FieldType::YesNo),
        normalizerField('c', FieldType::YesNo), normalizerField('d', FieldType::YesNo),
        normalizerField('e', FieldType::YesNo),
    ];

    expect(normalizeAnswers($fields, ['a' => true, 'b' => 'no', 'c' => 'yes', 'd' => '0', 'e' => 1]))
        ->toBe(['a' => true, 'b' => false, 'c' => true, 'd' => false, 'e' => true]);
});

it('normalises multi-select to a list of strings', function (): void {
    $fields = [normalizerField('tags', FieldType::MultiSelect), normalizerField('one', FieldType::MultiSelect)];

    expect(normalizeAnswers($fields, ['tags' => [1, 'b'], 'one' => 'solo']))
        ->toBe(['tags' => ['1', 'b'], 'one' => ['solo']]);
});

it('drops empty answers as unanswered', function (): void {
    $fields = [normalizerField('name', FieldType::ShortText), normalizerField('tags', FieldType::MultiSelect)];

    expect(normalizeAnswers($fields, ['name' => '', 'tags' => []]))->toBe([]);
});

it('drops display-only and computed field types', function (): void {
    $fields = [
        normalizerField('intro', FieldType::Note),
        normalizerField('brk', FieldType::PageBreak),
        normalizerField('total', FieldType::Calculated),
        normalizerField('name', FieldType::ShortText),
    ];

    expect(normalizeAnswers($fields, ['intro' => 'hi', 'brk' => 'x', 'total' => '5', 'name' => 'Ada']))
        ->toBe(['name' => 'Ada']);
});

it('aggregates every structural error rather than throwing on the first', function (): void {
    $fields = [normalizerField('age', FieldType::Integer), normalizerField('name', FieldType::ShortText)];

    try {
        normalizeAnswers($fields, ['age' => 'x', 'name' => ['array'], 'ghost' => 1]);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors())->toHaveCount(3);
    }
});

/*
|--------------------------------------------------------------------------
| Repeat groups (Increment G1) — a repeatable section's instances nest under the SECTION key.
|--------------------------------------------------------------------------
*/

it('nests a repeatable section and coerces each instance with the same per-field rules', function (): void {
    $section = repeatSection('hh');
    $fields = [
        repeatMember('name', 'hh'),
        repeatMember('age', 'hh', FieldType::Integer),
        repeatMember('sub', 'hh', FieldType::YesNo),
    ];

    $result = normalizeAnswers($fields, ['hh' => [
        ['name' => 'Bob', 'age' => 40, 'sub' => 'yes'],
        ['name' => 'Cleo', 'age' => 12, 'sub' => 'no'],
    ]], [$section]);

    expect($result)->toBe(['hh' => [
        ['name' => 'Bob', 'age' => 40, 'sub' => true],
        ['name' => 'Cleo', 'age' => 12, 'sub' => false],
    ]]);
});

it('drops empty inner answers and fully-empty instances, and an empty group entirely', function (): void {
    $section = repeatSection('hh');
    $fields = [repeatMember('name', 'hh'), repeatMember('age', 'hh', FieldType::Integer)];

    $result = normalizeAnswers($fields, [
        'hh' => [['name' => 'Bob', 'age' => ''], ['name' => '', 'age' => '']],
        'other' => [], // an empty repeat group is dropped whole
    ], [$section, repeatSection('other')]);

    // instance 0 keeps only name; instance 1 is fully empty → dropped; 'other' group dropped.
    expect($result)->toBe(['hh' => [['name' => 'Bob']]]);
});

it('rejects a non-list value for a repeatable section', function (): void {
    $section = repeatSection('hh');
    $fields = [repeatMember('name', 'hh')];

    try {
        normalizeAnswers($fields, ['hh' => ['name' => 'Bob']], [$section]); // object, not a list
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['field'])->toBe('hh')
            ->and($e->fieldErrors()[0]['rule'])->toBe('expected_instance_array');
    }
});

it('rejects a non-object instance element', function (): void {
    $section = repeatSection('hh');
    $fields = [repeatMember('name', 'hh')];

    try {
        normalizeAnswers($fields, ['hh' => ['scalar-not-object']], [$section]);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['field'])->toBe('hh[0]')
            ->and($e->fieldErrors()[0]['rule'])->toBe('expected_instance_object');
    }
});

it('rejects an unknown key inside an instance addressed by the instance path', function (): void {
    $section = repeatSection('hh');
    $fields = [repeatMember('name', 'hh')];

    try {
        normalizeAnswers($fields, ['hh' => [['name' => 'Bob', 'ghost' => 'x']]], [$section]);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['field'])->toBe('hh[0].ghost')
            ->and($e->fieldErrors()[0]['rule'])->toBe('unknown_field');
    }
});

it('rejects a repeat-member field submitted at the top level', function (): void {
    $section = repeatSection('hh');
    $fields = [repeatMember('name', 'hh')];

    try {
        normalizeAnswers($fields, ['name' => 'Bob'], [$section]); // must be inside hh[]
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['field'])->toBe('name')
            ->and($e->fieldErrors()[0]['rule'])->toBe('misplaced_repeat_field');
    }
});

it('propagates an inner type mismatch with the instance path', function (): void {
    $section = repeatSection('hh');
    $fields = [repeatMember('age', 'hh', FieldType::Integer)];

    try {
        normalizeAnswers($fields, ['hh' => [['age' => 'not-a-number']]], [$section]);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['field'])->toBe('hh[0].age')
            ->and($e->fieldErrors()[0]['rule'])->toBe('not_a_number');
    }
});
