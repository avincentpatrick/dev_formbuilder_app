<?php

declare(strict_types=1);

use App\Services\Ocr\OcrAnswerCarry;

/*
|--------------------------------------------------------------------------
| M129 — `D74`'s carry rule as a decision table: pure arrays in, answers and their provenance out.
|--------------------------------------------------------------------------
| No database. Each case states one reason an answer does or does not reach the reviewer's starting answers,
| because the review screen renders the shared encode controls, and a value handed to a control that cannot
| show it would be saved by a reviewer who never saw it.
|
| ⚠️ Helpers are prefixed `ocrCarry*`: Pest loads every test file into one process.
*/

/** @param  list<array<string, mixed>>  $fields */
function ocrCarrySnapshot(array $fields, array $sections = []): array
{
    return ['fields' => $fields, 'sections' => $sections];
}

/** @return array<string, mixed> */
function ocrCarryField(string $key, string $type, array $config = [], ?string $section = null): array
{
    return ['key' => $key, 'field_type' => $type, 'config' => $config, 'label' => ucfirst(str_replace('_', ' ', $key)), 'section_key' => $section];
}

/** @return array<string, mixed> */
function ocrCarryRead(string $type, mixed $value, string $tier = 'auto', ?string $text = null): array
{
    return ['type' => $type, 'state' => 'read', 'value' => $value, 'text' => $text ?? (is_scalar($value) ? (string) $value : 'x'), 'confidence' => 95, 'tier' => $tier, 'page' => 1, 'anchored_by' => 'key'];
}

/** @param  array<string, array<string, mixed>>  $read */
function ocrCarry(array $read, array $paper, ?array $current = null): array
{
    return (new OcrAnswerCarry)->carry(['fields' => $read], $paper, $current ?? $paper);
}

const OCR_CARRY_CHOICES = ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]];

it('carries what was read on the same printing, numbers as numbers', function (): void {
    $snapshot = ocrCarrySnapshot([
        ocrCarryField('name', 'short_text'), ocrCarryField('age', 'integer'), ocrCarryField('weight', 'decimal'),
        ocrCarryField('pick', 'single_select', OCR_CARRY_CHOICES), ocrCarryField('many', 'multi_select', OCR_CARRY_CHOICES),
        ocrCarryField('ok', 'yes_no'), ocrCarryField('when', 'date'),
    ]);

    $result = ocrCarry([
        'name' => ocrCarryRead('short_text', 'Ana'), 'age' => ocrCarryRead('integer', '41'),
        'weight' => ocrCarryRead('decimal', '61.5'), 'pick' => ocrCarryRead('single_select', 'b'),
        'many' => ocrCarryRead('multi_select', ['a', 'b']), 'ok' => ocrCarryRead('yes_no', true),
        'when' => ocrCarryRead('date', '2026-10-01'),
    ], $snapshot);

    expect($result['answers'])->toBe([
        'name' => 'Ana', 'age' => 41, 'weight' => 61.5, 'pick' => 'b', 'many' => ['a', 'b'], 'ok' => true, 'when' => '2026-10-01',
    ])->and($result['dropped'])->toBe([]);
});

it('leaves a withheld answer empty without listing it, and keeps what was seen for the note', function (): void {
    $result = ocrCarry(['name' => ocrCarryRead('short_text', null, 'manual', 'An?')], ocrCarrySnapshot([ocrCarryField('name', 'short_text')]));

    expect($result['answers'])->toBe([])
        ->and($result['dropped'])->toBe([])
        ->and($result['fields']['name']['text'])->toBe('An?')
        ->and($result['fields']['name']['carried'])->toBeFalse()
        ->and($result['fields']['name']['reason'])->toBeNull();
});

it('lists a duration, which no channel can show, instead of saving it unseen', function (): void {
    $result = ocrCarry(['spent' => ocrCarryRead('duration', 5400, 'auto', '1 30')], ocrCarrySnapshot([ocrCarryField('spent', 'duration')]));

    expect($result['answers'])->toBe([])
        ->and($result['dropped'][0]['reason'])->toBe('not_answerable')
        ->and($result['dropped'][0]['text'])->toBe('1 30');
});

it('refuses a value whose question changed type since the paper was printed', function (): void {
    $result = ocrCarry(
        ['age' => ocrCarryRead('integer', '41')],
        ocrCarrySnapshot([ocrCarryField('age', 'integer')]),
        ocrCarrySnapshot([ocrCarryField('age', 'short_text')]),
    );

    expect($result['answers'])->toBe([])->and($result['dropped'][0]['reason'])->toBe('type_changed');
});

it('refuses a choice the current question no longer offers, single or among several', function (): void {
    $paper = ocrCarrySnapshot([ocrCarryField('pick', 'single_select', OCR_CARRY_CHOICES), ocrCarryField('many', 'multi_select', OCR_CARRY_CHOICES)]);
    $current = ocrCarrySnapshot([
        ocrCarryField('pick', 'single_select', ['options' => [['value' => 'a', 'label' => 'A']]]),
        ocrCarryField('many', 'multi_select', ['options' => [['value' => 'a', 'label' => 'A']]]),
    ]);

    $result = ocrCarry(['pick' => ocrCarryRead('single_select', 'b'), 'many' => ocrCarryRead('multi_select', ['a', 'b'])], $paper, $current);

    expect($result['answers'])->toBe([])
        ->and(array_column($result['dropped'], 'reason'))->toBe(['option_missing', 'option_missing']);
});

it('carries a cascade only when every level still offers the value under its parent', function (): void {
    $config = ['levels' => [['key' => 'region'], ['key' => 'town']], 'options' => [
        ['value' => 'north', 'level' => 'region'], ['value' => 'south', 'level' => 'region'],
        ['value' => 'alpha', 'level' => 'town', 'parent' => 'north'], ['value' => 'beta', 'level' => 'town', 'parent' => 'south'],
    ]];
    $snapshot = ocrCarrySnapshot([ocrCarryField('place', 'cascading_select', $config)]);

    expect(ocrCarry(['place' => ocrCarryRead('cascading_select', ['north', 'alpha'])], $snapshot)['answers'])->toBe(['place' => ['north', 'alpha']])
        ->and(ocrCarry(['place' => ocrCarryRead('cascading_select', ['north', 'beta'])], $snapshot)['dropped'][0]['reason'])->toBe('option_missing');
});

it('refuses a question that now sits inside a repeating section', function (): void {
    $result = ocrCarry(
        ['name' => ocrCarryRead('short_text', 'Ana')],
        ocrCarrySnapshot([ocrCarryField('name', 'short_text')]),
        ocrCarrySnapshot([ocrCarryField('name', 'short_text', [], 'members')], [['key' => 'members', 'is_repeatable' => true]]),
    );

    expect($result['answers'])->toBe([])->and($result['dropped'][0]['reason'])->toBe('repeat');
});

it('lists a question the current version dropped — a value or text the reader withheld — but not an empty one', function (): void {
    $paper = ocrCarrySnapshot([ocrCarryField('kept', 'short_text'), ocrCarryField('gone', 'short_text'), ocrCarryField('faint', 'short_text'), ocrCarryField('empty', 'short_text')]);
    $current = ocrCarrySnapshot([ocrCarryField('kept', 'short_text')]);

    $result = ocrCarry([
        'kept' => ocrCarryRead('short_text', 'x'),
        'gone' => ocrCarryRead('short_text', 'Came back'),
        'faint' => ['type' => 'short_text', 'state' => 'unreadable', 'value' => null, 'text' => 'sc?', 'confidence' => 20, 'tier' => null, 'page' => 1, 'anchored_by' => 'label'],
        'empty' => ['type' => 'short_text', 'state' => 'blank', 'value' => null, 'text' => null, 'confidence' => null, 'tier' => null, 'page' => 1, 'anchored_by' => 'key'],
    ], $paper, $current);

    expect(array_column($result['dropped'], 'key'))->toBe(['gone', 'faint'])
        ->and(array_unique(array_column($result['dropped'], 'reason')))->toBe(['question_removed'])
        ->and($result['dropped'][0]['label'])->toBe('Gone');
});

it('marks a question the paper never printed, and takes nothing into a display-only one', function (): void {
    $result = ocrCarry(
        ['name' => ocrCarryRead('short_text', 'Ana')],
        ocrCarrySnapshot([ocrCarryField('name', 'short_text')]),
        ocrCarrySnapshot([ocrCarryField('name', 'short_text'), ocrCarryField('phone', 'phone'), ocrCarryField('intro', 'note'), ocrCarryField('ref', 'hidden')]),
    );

    expect($result['fields']['phone']['state'])->toBe('not_on_paper')
        ->and(array_key_exists('intro', $result['fields']))->toBeFalse()
        ->and(array_key_exists('ref', $result['fields']))->toBeFalse()
        ->and($result['answers'])->toBe(['name' => 'Ana']);
});
