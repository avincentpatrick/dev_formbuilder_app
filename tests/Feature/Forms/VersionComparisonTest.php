<?php

declare(strict_types=1);

use App\Services\Forms\VersionComparison;

/*
|--------------------------------------------------------------------------
| M189 (`version-diff-ui`, `D169`) — two versions of a form compared question by question, setting by setting.
|--------------------------------------------------------------------------
| PRD Feature #8's acceptance line: "An interactive, field-by-field version-comparison view (draft vs. currently-published,
| or any two historical versions)". Plain snapshot arrays in the shape `SchemaSnapshotSerializer::snapshot()` writes, so
| every rule is pinned without a database.
*/

/**
 * One question in the snapshot shape, every setting at a neutral value.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function snapshotQuestion(string $key, int $sequence, array $overrides = []): array
{
    return [
        'key' => $key,
        'section_key' => 'household',
        'field_type' => 'short_text',
        'config' => [],
        'label' => ucfirst(str_replace('_', ' ', $key)),
        'label_translations' => null,
        'hint' => null,
        'hint_translations' => null,
        'placeholder' => null,
        'default_value' => null,
        'default_value_is_expression' => false,
        'is_required' => 'optional',
        'relevant_expression' => null,
        'appearance' => null,
        'sequence' => $sequence,
        'section_sequence' => 1,
        'is_pii' => false,
        'is_sensitive' => false,
        'is_queryable' => false,
        'indexed_data_type' => null,
        'validations' => [],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function snapshotSection(string $key, int $sequence, array $overrides = []): array
{
    return [
        'key' => $key,
        'label' => ucfirst($key),
        'label_translations' => null,
        'description' => null,
        'description_translations' => null,
        'sequence' => $sequence,
        'icon' => null,
        'color' => null,
        'is_repeatable' => false,
        'min_instances' => null,
        'max_instances' => null,
        'relevant_expression' => null,
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $fields
 * @param  list<array<string, mixed>>|null  $sections
 * @return array<string, mixed>
 */
function snapshotOf(array $fields, ?array $sections = null): array
{
    return ['sections' => $sections ?? [snapshotSection('household', 1)], 'fields' => $fields];
}

/**
 * @param  array<string, mixed>  $comparison
 * @return array<string, mixed>
 */
function comparedQuestion(array $comparison, string $key): array
{
    $found = array_values(array_filter($comparison['questions'], fn (array $q): bool => $q['key'] === $key));
    expect($found)->toHaveCount(1, "question {$key}");

    return $found[0];
}

/**
 * @param  array<string, mixed>  $question
 * @return list<string>
 */
function changedSettings(array $question): array
{
    return array_map(fn (array $change): string => $change['setting'], $question['changes']);
}

it('lists added, removed, changed and unchanged questions by key', function (): void {
    $from = snapshotOf([snapshotQuestion('name', 1), snapshotQuestion('age', 2), snapshotQuestion('color', 3)]);
    $to = snapshotOf([snapshotQuestion('name', 1), snapshotQuestion('age', 2, ['is_required' => 'required']), snapshotQuestion('notes', 3)]);

    $comparison = (new VersionComparison)->compare($from, $to);

    expect(comparedQuestion($comparison, 'name')['status'])->toBe('same')
        ->and(comparedQuestion($comparison, 'age')['status'])->toBe('changed')
        ->and(changedSettings(comparedQuestion($comparison, 'age')))->toBe(['is_required'])
        ->and(comparedQuestion($comparison, 'notes')['status'])->toBe('added')
        ->and(comparedQuestion($comparison, 'color')['status'])->toBe('removed')
        // The To side's order first, then what only the From side had, in its order.
        ->and(array_column($comparison['questions'], 'key'))->toBe(['name', 'age', 'notes', 'color']);
});

it('words a change of choices or rules as the setting it is, like the History tab', function (): void {
    $choices = fn (array $labels): array => ['options' => array_map(fn (string $l): array => ['value' => strtolower($l), 'label' => $l], $labels)];
    $from = snapshotOf([snapshotQuestion('fruit', 1, ['field_type' => 'single_choice', 'config' => $choices(['Apple', 'Pear'])])]);
    $to = snapshotOf([snapshotQuestion('fruit', 1, ['field_type' => 'single_choice', 'config' => $choices(['Apple', 'Plum'])])]);

    $question = comparedQuestion((new VersionComparison)->compare($from, $to), 'fruit');

    expect($question['status'])->toBe('changed')
        ->and($question['changes'])->toHaveCount(1)
        ->and($question['changes'][0]['setting'])->toBe('config.options')
        ->and($question['changes'][0]['before'][1]['label'])->toBe('Pear')
        ->and($question['changes'][0]['after'][1]['label'])->toBe('Plum')
        ->and($question['marks'])->toBe([]);
});

it('counts a translation-only change as a change', function (): void {
    $from = snapshotOf([snapshotQuestion('name', 1, ['label_translations' => ['fil' => 'Pangalan']])]);
    $to = snapshotOf([snapshotQuestion('name', 1, ['label_translations' => ['fil' => 'Buong pangalan']])]);

    expect(changedSettings(comparedQuestion((new VersionComparison)->compare($from, $to), 'name')))->toBe(['label_translations']);
});

it('reads an empty translation map, an empty config and renumbered rules as unchanged', function (): void {
    $rule = fn (int $sequence): array => [
        'rule_type' => 'max_length', 'operator' => null, 'rule_value' => '40', 'expression' => null, 'error_message' => null,
        'error_message_translations' => null, 'related_field_key' => null, 'logic_group_ordinal' => null, 'logic_operator' => null,
        'sequence' => $sequence,
    ];
    $from = snapshotOf([snapshotQuestion('name', 1, ['label_translations' => null, 'validations' => [$rule(1)]])]);
    $to = snapshotOf([snapshotQuestion('name', 1, ['label_translations' => [], 'config' => [], 'validations' => [$rule(7)]])]);

    expect(comparedQuestion((new VersionComparison)->compare($from, $to), 'name')['status'])->toBe('same');
});

it('moves no other question when an earlier one is deleted', function (): void {
    $from = snapshotOf([snapshotQuestion('a', 1), snapshotQuestion('b', 2), snapshotQuestion('c', 3), snapshotQuestion('d', 4)]);
    $to = snapshotOf([snapshotQuestion('a', 1), snapshotQuestion('c', 2), snapshotQuestion('d', 3)]);

    $comparison = (new VersionComparison)->compare($from, $to);

    foreach (['a', 'c', 'd'] as $key) {
        expect(comparedQuestion($comparison, $key)['status'])->toBe('same', "{$key} only renumbered");
    }
});

it('marks only the dragged question as moved, with its place before and after', function (): void {
    $from = snapshotOf([snapshotQuestion('a', 1), snapshotQuestion('b', 2), snapshotQuestion('c', 3)]);
    $to = snapshotOf([snapshotQuestion('c', 1), snapshotQuestion('a', 2), snapshotQuestion('b', 3)]);

    $comparison = (new VersionComparison)->compare($from, $to);
    $moved = comparedQuestion($comparison, 'c');

    expect(comparedQuestion($comparison, 'a')['status'])->toBe('same')
        ->and(comparedQuestion($comparison, 'b')['status'])->toBe('same')
        ->and(changedSettings($moved))->toBe(['position'])
        ->and($moved['changes'][0]['before'])->toBe(['section' => 'Household', 'position' => 3])
        ->and($moved['changes'][0]['after'])->toBe(['section' => 'Household', 'position' => 1]);
});

it('shows a move to another section as a change of place', function (): void {
    $sections = [snapshotSection('household', 1), snapshotSection('visit', 2)];
    $from = snapshotOf([snapshotQuestion('a', 1), snapshotQuestion('b', 2)], $sections);
    $to = snapshotOf([snapshotQuestion('a', 1), snapshotQuestion('b', 2, ['section_key' => 'visit'])], $sections);

    $moved = comparedQuestion((new VersionComparison)->compare($from, $to), 'b');

    expect(changedSettings($moved))->toBe(['position'])
        ->and($moved['changes'][0]['after'])->toBe(['section' => 'Visit', 'position' => 1])
        ->and($moved['section_key'])->toBe('visit');
});

it('marks each change that makes answers hard to compare across versions (§5)', function (array $before, array $after, string $code): void {
    $from = snapshotOf([snapshotQuestion('q', 1, $before)]);
    $to = snapshotOf([snapshotQuestion('q', 1, $after)]);

    $question = comparedQuestion((new VersionComparison)->compare($from, $to), 'q');

    expect($question['breaking'])->toBe($code)
        ->and($question['marks'])->toContain(VersionComparison::COMPARABILITY);
})->with([
    'a new type' => [['field_type' => 'short_text'], ['field_type' => 'integer'], 'type'],
    'made required' => [['is_required' => 'optional'], ['is_required' => 'required'], 'required'],
    'indexing switched on' => [['is_queryable' => false], ['is_queryable' => true, 'indexed_data_type' => 'text'], 'indexing'],
    'the indexed type changed' => [['is_queryable' => true, 'indexed_data_type' => 'text'], ['is_queryable' => true, 'indexed_data_type' => 'number'], 'indexing'],
]);

it('marks a removed question as hard to compare, and leaves a required question made optional unmarked', function (): void {
    $from = snapshotOf([snapshotQuestion('kept', 1, ['is_required' => 'required']), snapshotQuestion('gone', 2)]);
    $to = snapshotOf([snapshotQuestion('kept', 1, ['is_required' => 'optional'])]);

    $comparison = (new VersionComparison)->compare($from, $to);

    expect(comparedQuestion($comparison, 'gone')['breaking'])->toBe('removed')
        ->and(comparedQuestion($comparison, 'gone')['marks'])->toBe([VersionComparison::COMPARABILITY])
        ->and(comparedQuestion($comparison, 'kept')['breaking'])->toBeNull()
        ->and(comparedQuestion($comparison, 'kept')['marks'])->toBe([]);
});

it('marks a change to who is asked, on a question and on a section', function (): void {
    $from = snapshotOf([snapshotQuestion('q', 1)], [snapshotSection('household', 1)]);
    $to = snapshotOf(
        [snapshotQuestion('q', 1, ['relevant_expression' => '${age} >= 18'])],
        [snapshotSection('household', 1, ['relevant_expression' => '${consent} = \'yes\''])],
    );

    $comparison = (new VersionComparison)->compare($from, $to);

    expect(comparedQuestion($comparison, 'q')['marks'])->toBe([VersionComparison::ASKED])
        ->and($comparison['sections'][0]['status'])->toBe('changed')
        ->and($comparison['sections'][0]['marks'])->toBe([VersionComparison::ASKED])
        ->and(array_column($comparison['sections'][0]['changes'], 'setting'))->toBe(['relevant_expression']);
});

it('marks a text change that pipes in different answers, and leaves a prose-only change unmarked (piping §6.1)', function (): void {
    $from = snapshotOf([snapshotQuestion('piped', 1, ['label' => 'Age of the child']), snapshotQuestion('prose', 2, ['hint' => 'In years, ${unit}'])]);
    $to = snapshotOf([snapshotQuestion('piped', 1, ['label' => 'Age of ${child_name}']), snapshotQuestion('prose', 2, ['hint' => 'Whole years, ${unit}'])]);

    $comparison = (new VersionComparison)->compare($from, $to);

    expect(comparedQuestion($comparison, 'piped')['marks'])->toBe([VersionComparison::PIPING])
        ->and(comparedQuestion($comparison, 'prose')['status'])->toBe('changed')
        ->and(comparedQuestion($comparison, 'prose')['marks'])->toBe([]);
});

it('compares the list a question names, never the rows publish copied in from it', function (): void {
    $published = snapshotQuestion('facility', 1, ['field_type' => 'single_choice', 'config' => [
        'options_list' => 'facilities.csv',
        'options' => [['value' => 'a', 'label' => 'Clinic A'], ['value' => 'b', 'label' => 'Clinic B']],
    ]]);
    $draft = snapshotQuestion('facility', 1, ['field_type' => 'single_choice', 'config' => ['options_list' => 'facilities.csv', 'options' => []]]);

    $comparison = (new VersionComparison)->compare(snapshotOf([$published]), snapshotOf([$draft]));

    expect(comparedQuestion($comparison, 'facility')['status'])->toBe('same');
});

it('says a section was added or removed, and what changed on one kept', function (): void {
    $from = snapshotOf([snapshotQuestion('a', 1)], [snapshotSection('household', 1), snapshotSection('old', 2)]);
    $to = snapshotOf(
        [snapshotQuestion('a', 1)],
        [snapshotSection('household', 1, ['label' => 'Your household', 'is_repeatable' => true]), snapshotSection('new', 2)],
    );

    $sections = (new VersionComparison)->compare($from, $to)['sections'];

    expect(array_column($sections, 'key'))->toBe(['household', 'new', 'old'])
        ->and(array_column($sections, 'status'))->toBe(['changed', 'added', 'removed'])
        ->and(array_column($sections[0]['changes'], 'setting'))->toBe(['label', 'is_repeatable'])
        ->and($sections[0]['label'])->toBe('Your household')
        ->and($sections[2]['marks'])->toBe([VersionComparison::COMPARABILITY]);
});

it('fingerprints a question by its settings, never by where it sits', function (): void {
    $here = snapshotQuestion('q', 1);
    $moved = snapshotQuestion('q', 9, ['section_key' => 'visit', 'section_sequence' => 4]);
    $relabelled = snapshotQuestion('q', 1, ['hint' => 'Something new']);

    expect(VersionComparison::fingerprint($here))->toBe(VersionComparison::fingerprint($moved))
        ->and(VersionComparison::fingerprint($here))->not->toBe(VersionComparison::fingerprint($relabelled));
});
