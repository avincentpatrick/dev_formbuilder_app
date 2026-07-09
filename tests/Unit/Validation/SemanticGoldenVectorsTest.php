<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\FieldType;
use App\Enums\LogicOperator;
use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
use App\Services\Expressions\ExpressionEvaluator;
use App\Services\Validation\SemanticError;
use App\Services\Validation\SemanticInput;
use Illuminate\Support\Collection;

/*
|--------------------------------------------------------------------------
| F3 golden semantic-validation runner (the validation drift contract, Risk R3).
|--------------------------------------------------------------------------
| Pure Pest Unit (no DB, no container). Iterates the language-neutral fixtures under
| tests/golden/validation/*.json — a schema fragment + answers → the expected relevance mask, errors,
| effective answers, and computed values. These are the SAME files the Phase-1 TypeScript engine will run,
| so relevance / required / skip / constraint drift between the two engines is a CI failure. Ids are set
| equal to keys, so field references resolve through an identity id→key map.
*/

function semanticGoldenDir(): string
{
    return dirname(__DIR__, 2).'/golden/validation';
}

/**
 * @param  array<string, mixed>  $schema
 * @param  array<string, mixed>  $answers
 */
function buildSemanticInput(array $schema, array $answers, string $locale): SemanticInput
{
    $sections = [];
    foreach (($schema['sections'] ?? []) as $index => $section) {
        $attributes = ['id' => $section['key'], 'key' => $section['key'], 'sequence' => $index];
        if (isset($section['relevant'])) {
            $attributes['relevant_expression'] = $section['relevant'];
        }
        $sections[] = makeSchemaSection($attributes);
    }

    $fields = [];
    $validations = [];
    foreach ($schema['fields'] as $index => $field) {
        $attributes = ['id' => $field['key'], 'key' => $field['key'], 'sequence' => $index];
        if (isset($field['field_type'])) {
            $attributes['field_type'] = FieldType::from($field['field_type']);
        }
        if (isset($field['is_required'])) {
            $attributes['is_required'] = RequiredMode::from($field['is_required']);
        }
        if (isset($field['section'])) {
            $attributes['form_section_id'] = $field['section'];
        }
        if (isset($field['relevant'])) {
            $attributes['relevant_expression'] = $field['relevant'];
        }
        $fields[] = makeSchemaField($attributes);

        foreach (($field['rules'] ?? []) as $ruleIndex => $rule) {
            $validations[] = makeValidationRow(buildRuleAttributes($rule, $field['key'], $ruleIndex));
        }
    }

    return new SemanticInput(new Collection($fields), new Collection($sections), new Collection($validations), $answers, $locale);
}

/**
 * @param  array<string, mixed>  $rule
 * @return array<string, mixed>
 */
function buildRuleAttributes(array $rule, string $fieldKey, int $index): array
{
    $attributes = [
        'id' => $rule['id'] ?? "{$fieldKey}-{$index}",
        'form_field_id' => $fieldKey,
        'sequence' => $rule['sequence'] ?? $index,
    ];
    if (isset($rule['rule_type'])) {
        $attributes['rule_type'] = ValidationRuleType::from($rule['rule_type']);
    }
    if (isset($rule['operator'])) {
        $attributes['operator'] = ComparisonOperator::from($rule['operator']);
    }
    if (isset($rule['related'])) {
        $attributes['related_form_field_id'] = $rule['related'];
    }
    if (isset($rule['rule_value'])) {
        $attributes['rule_value'] = $rule['rule_value'];
    }
    if (isset($rule['expression'])) {
        $attributes['expression'] = $rule['expression'];
    }
    if (isset($rule['logic_group'])) {
        $attributes['logic_group'] = $rule['logic_group'];
    }
    if (isset($rule['logic_operator'])) {
        $attributes['logic_operator'] = LogicOperator::from($rule['logic_operator']);
    }
    if (isset($rule['error_message'])) {
        $attributes['error_message'] = $rule['error_message'];
    }
    if (isset($rule['error_message_translations'])) {
        $attributes['error_message_translations'] = $rule['error_message_translations'];
    }

    return $attributes;
}

/**
 * @param  list<array{field: string, rule: string}>  $errors
 * @return list<array{field: string, rule: string}>
 */
function sortSemanticErrors(array $errors): array
{
    usort($errors, static fn (array $a, array $b): int => [$a['field'], $a['rule']] <=> [$b['field'], $b['rule']]);

    return $errors;
}

/**
 * @return list<array<string, mixed>>
 */
function loadSemanticVectorFiles(): array
{
    $out = [];

    foreach (glob(semanticGoldenDir().'/*.json') ?: [] as $file) {
        if (basename($file) === 'manifest.json') {
            continue;
        }

        /** @var list<array<string, mixed>> $cases */
        $cases = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        foreach ($cases as $case) {
            $out[] = $case;
        }
    }

    return $out;
}

dataset('semantic vectors', function (): iterable {
    foreach (loadSemanticVectorFiles() as $case) {
        /** @var string $name */
        $name = $case['name'];
        yield $name => [$case];
    }
});

it('matches the golden semantic vector', function (array $case): void {
    expect($case['grammar_version'])->toBe(ExpressionEvaluator::GRAMMAR_VERSION);

    $input = buildSemanticInput($case['schema'], $case['answers'] ?? [], $case['locale'] ?? 'en');
    $result = makeSemanticValidator()->evaluate($input);

    /** @var array<string, mixed> $expected */
    $expected = $case['expected'];

    expect($result->fieldRelevance)->toEqual($expected['relevance']['fields'] ?? []);
    expect($result->sectionRelevance)->toEqual($expected['relevance']['sections'] ?? []);
    expect($result->effectiveAnswers)->toEqual($expected['effective_answers'] ?? []);
    expect($result->computed)->toEqual($expected['computed'] ?? []);

    $actual = array_map(
        static fn (SemanticError $error): array => ['field' => $error->fieldKey, 'rule' => $error->rule],
        $result->errors,
    );
    expect(sortSemanticErrors($actual))->toEqual(sortSemanticErrors($expected['errors'] ?? []));
})->with('semantic vectors');

it('enforces the semantic manifest count-parity, per-file, and name-uniqueness guards', function (): void {
    /** @var array{total: int, files: array<string, int>} $manifest */
    $manifest = json_decode((string) file_get_contents(semanticGoldenDir().'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

    $perFile = [];
    $names = [];
    $loaded = 0;

    foreach (glob(semanticGoldenDir().'/*.json') ?: [] as $file) {
        if (basename($file) === 'manifest.json') {
            continue;
        }

        /** @var list<array<string, mixed>> $cases */
        $cases = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $perFile[basename($file, '.json')] = count($cases);
        $loaded += count($cases);

        foreach ($cases as $case) {
            $names[] = (string) $case['name'];
        }
    }

    expect($loaded)->toBe($manifest['total']);
    expect($perFile)->toEqual($manifest['files']);
    expect(count(array_unique($names)))->toBe($manifest['total']);
});
