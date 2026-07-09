<?php

declare(strict_types=1);

use App\Enums\IndexedDataType;
use App\Models\FormField;
use App\Services\Submissions\AnswerIndexProjector;
use Illuminate\Support\Carbon;

/**
 * Stage-4 typed projection, driven purely over UNSAVED FormField models. Verifies each IndexedDataType
 * maps to its designated value_* column and that nothing is projected for non-queryable / empty / array /
 * unparseable answers. No container, no Postgres.
 */
function projectorField(?IndexedDataType $type, bool $queryable = true): FormField
{
    return makeSchemaField(['key' => 'q', 'is_queryable' => $queryable, 'indexed_data_type' => $type]);
}

function project(FormField $field, mixed $answer): ?array
{
    return (new AnswerIndexProjector)->project($field, $answer);
}

it('projects nothing for a non-queryable field', function (): void {
    expect(project(projectorField(IndexedDataType::Text, queryable: false), 'x'))->toBeNull();
});

it('projects nothing when no index type is declared', function (): void {
    expect(project(projectorField(null), 'x'))->toBeNull();
});

it('projects a text value into value_text', function (): void {
    expect(project(projectorField(IndexedDataType::Text), 42))
        ->toBe(['column' => 'value_text', 'value' => '42']);
});

it('projects a numeric value into value_number and skips non-numeric', function (): void {
    expect(project(projectorField(IndexedDataType::Number), '5'))
        ->toBe(['column' => 'value_number', 'value' => 5.0]);

    expect(project(projectorField(IndexedDataType::Number), 'abc'))->toBeNull();
});

it('projects a boolean value into value_boolean', function (): void {
    expect(project(projectorField(IndexedDataType::Boolean), '1'))
        ->toBe(['column' => 'value_boolean', 'value' => true]);

    expect(project(projectorField(IndexedDataType::Boolean), '0'))
        ->toBe(['column' => 'value_boolean', 'value' => false]);
});

it('parses a date into value_date and skips a malformed one', function (): void {
    $projection = project(projectorField(IndexedDataType::Date), '2026-07-09');

    expect($projection['column'])->toBe('value_date')
        ->and($projection['value'])->toBeInstanceOf(Carbon::class)
        ->and($projection['value']->format('Y-m-d'))->toBe('2026-07-09');

    expect(project(projectorField(IndexedDataType::Date), 'not-a-date'))->toBeNull();
});

it('parses a datetime into value_datetime', function (): void {
    $projection = project(projectorField(IndexedDataType::Datetime), '2026-07-09 10:30:00');

    expect($projection['column'])->toBe('value_datetime')
        ->and($projection['value'])->toBeInstanceOf(Carbon::class)
        ->and($projection['value']->format('Y-m-d H:i'))->toBe('2026-07-09 10:30');
});

it('projects nothing for an empty or array answer', function (): void {
    expect(project(projectorField(IndexedDataType::Text), ''))->toBeNull()
        ->and(project(projectorField(IndexedDataType::Text), []))->toBeNull()
        ->and(project(projectorField(IndexedDataType::Text), ['a', 'b']))->toBeNull();
});
