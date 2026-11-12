<?php

declare(strict_types=1);

use App\Exceptions\Explorer\InvalidExplorerQueryException;
use App\Support\Explorer\ExplorerQuery;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| M236 — the data explorer's declaration (ADR-0025 §D2, §D3).
|
| The bounds live in the value object's constructor so that a request, a stored chart and a test all pass
| the same checks. These cases therefore need no database: an unbounded query is unrepresentable.
|--------------------------------------------------------------------------
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function explorerDefinition(array $overrides = []): array
{
    return [
        'v' => 1,
        'source' => 'responses',
        'form_id' => null,
        'chart' => 'bar',
        'dimensions' => [['field' => 'status']],
        'measures' => [['aggregate' => 'count']],
        'filters' => [],
        'from' => '2026-01-01',
        'to' => '2026-03-31',
        'timezone' => 'Asia/Manila',
        ...$overrides,
    ];
}

function explorerRefusal(array $definition): string
{
    try {
        ExplorerQuery::fromArray($definition);
    } catch (InvalidExplorerQueryException $e) {
        return $e->reason();
    }

    return 'accepted';
}

it('round-trips a declaration through its stored shape unchanged', function (): void {
    $query = ExplorerQuery::fromArray(explorerDefinition([
        'chart' => 'column',
        'dimensions' => [['field' => 'submitted_at', 'bucket' => 'month'], ['field' => 'status']],
        'filters' => [['field' => 'source', 'operator' => 'in', 'values' => ['guest', 'manual']]],
        'sort' => 'label',
        'limit' => 7,
    ]));

    $stored = $query->toArray();

    expect($stored['v'])->toBe(ExplorerQuery::SCHEMA_VERSION)
        ->and($stored['dimensions'])->toBe([['field' => 'submitted_at', 'bucket' => 'month'], ['field' => 'status', 'bucket' => null]])
        ->and($stored['filters'])->toBe([['field' => 'source', 'operator' => 'in', 'values' => ['guest', 'manual']]])
        ->and($stored['limit'])->toBe(7)
        ->and(ExplorerQuery::fromArray($stored)->toArray())->toBe($stored);
});

it('turns the local dates into a half-open pair of instants in the declared zone', function (): void {
    $query = ExplorerQuery::fromArray(explorerDefinition(['from' => '2026-03-01', 'to' => '2026-03-01']));

    // Manila is UTC+8 with no daylight saving: its 1 March runs from 28 Feb 16:00Z to 1 Mar 16:00Z.
    expect($query->fromUtc()->toIso8601String())->toBe('2026-02-28T16:00:00+00:00')
        ->and($query->toUtcExclusive()->toIso8601String())->toBe('2026-03-01T16:00:00+00:00')
        ->and($query->spanInDays())->toBe(1);
});

it('refuses a range it cannot bound', function (array $overrides, string $reason): void {
    expect(explorerRefusal(explorerDefinition($overrides)))->toBe($reason);
})->with([
    'no range at all' => [['from' => null], 'range_missing'],
    'a date that does not exist' => [['from' => '2026-02-31'], 'range_missing'],
    'a date with a time on it' => [['to' => '2026-03-31T00:00:00'], 'range_missing'],
    'the end before the start' => [['from' => '2026-04-01'], 'range_inverted'],
    'longer than the maximum' => [['from' => '2022-01-01'], 'range_too_long'],
    'a zone that is not one' => [['timezone' => 'Mars/Olympus'], 'unknown_timezone'],
    'no zone' => [['timezone' => null], 'unknown_timezone'],
]);

it('accepts a range of exactly the maximum and refuses one day more', function (): void {
    $max = ExplorerQuery::MAX_RANGE_DAYS;
    $from = CarbonImmutable::parse('2026-12-31')->subDays($max - 1)->toDateString();
    $over = CarbonImmutable::parse('2026-12-31')->subDays($max)->toDateString();

    expect(explorerRefusal(explorerDefinition(['from' => $from, 'to' => '2026-12-31'])))->toBe('accepted')
        ->and(explorerRefusal(explorerDefinition(['from' => $over, 'to' => '2026-12-31'])))->toBe('range_too_long');
});

it('holds each chart type to the group-bys and values it can draw', function (array $overrides, string $reason): void {
    expect(explorerRefusal(explorerDefinition($overrides)))->toBe($reason);
})->with([
    'a number with a group-by' => [['chart' => 'number'], 'chart_shape'],
    'bars with nothing to group by' => [['dimensions' => []], 'chart_shape'],
    'bars with two group-bys' => [['dimensions' => [['field' => 'status'], ['field' => 'source']]], 'chart_shape'],
    'a donut with two values' => [['chart' => 'donut', 'measures' => [['aggregate' => 'count'], ['aggregate' => 'sum', 'field' => 'completeness']]], 'chart_shape'],
    'columns split by a second field, with two values' => [[
        'chart' => 'column',
        'dimensions' => [['field' => 'status'], ['field' => 'source']],
        'measures' => [['aggregate' => 'count'], ['aggregate' => 'sum', 'field' => 'completeness']],
    ], 'chart_shape'],
    'the same field grouped twice' => [['chart' => 'column', 'dimensions' => [['field' => 'status'], ['field' => 'status']]], 'duplicate_dimension'],
    'no value' => [['measures' => []], 'chart_shape'],
    'a sum of nothing' => [['measures' => [['aggregate' => 'sum']]], 'measure_needs_field'],
    'three group-bys' => [['chart' => 'table', 'dimensions' => [['field' => 'a'], ['field' => 'b'], ['field' => 'c']]], 'too_many'],
    'four values' => [['chart' => 'table', 'measures' => array_fill(0, 4, ['aggregate' => 'count'])], 'too_many'],
    'nine filters' => [['filters' => array_fill(0, 9, ['field' => 'status', 'operator' => 'is_empty'])], 'too_many'],
    'a limit of zero' => [['limit' => 0], 'limit_out_of_range'],
    'a limit past the maximum' => [['limit' => ExplorerQuery::MAX_LIMIT + 1], 'limit_out_of_range'],
]);

it('accepts the shapes each chart type is for', function (array $overrides): void {
    expect(explorerRefusal(explorerDefinition($overrides)))->toBe('accepted');
})->with([
    'a number over three values' => [['chart' => 'number', 'dimensions' => [], 'measures' => array_fill(0, 3, ['aggregate' => 'count'])]],
    'a table of totals' => [['chart' => 'table', 'dimensions' => []]],
    'a table with two group-bys and three values' => [[
        'chart' => 'table',
        'dimensions' => [['field' => 'status'], ['field' => 'source']],
        'measures' => array_fill(0, 3, ['aggregate' => 'count']),
    ]],
    'a line of three values over time' => [[
        'chart' => 'line',
        'dimensions' => [['field' => 'submitted_at', 'bucket' => 'week']],
        'measures' => array_fill(0, 3, ['aggregate' => 'count']),
    ]],
    'an area split by a second field' => [['chart' => 'area', 'dimensions' => [['field' => 'submitted_at', 'bucket' => 'day'], ['field' => 'status']]]],
]);

it('reads anything at all as a declaration or as one refusal, never as a type error', function (mixed $definition): void {
    expect(fn () => ExplorerQuery::fromArray($definition))->toThrow(InvalidExplorerQueryException::class);
})->with([
    'nothing' => [[]],
    'a newer format' => [explorerDefinition(['v' => 2])],
    'a source that is a list' => [explorerDefinition(['source' => ['responses']])],
    'a chart type this version does not know' => [explorerDefinition(['chart' => 'sankey'])],
    'group-bys that are a string' => [explorerDefinition(['dimensions' => 'status'])],
    'a group-by that is a string' => [explorerDefinition(['dimensions' => ['status']])],
    'a group-by with no field' => [explorerDefinition(['dimensions' => [['bucket' => 'day']]])],
    'a date grouping that is not one' => [explorerDefinition(['dimensions' => [['field' => 'submitted_at', 'bucket' => 'fortnight']]])],
    'a value that is not one' => [explorerDefinition(['measures' => [['aggregate' => 'mode']]])],
    'a filter with a nested value' => [explorerDefinition(['filters' => [['field' => 'status', 'operator' => 'in', 'values' => [['x']]]]])],
    'a filter with too many values' => [explorerDefinition(['filters' => [['field' => 'status', 'operator' => 'in', 'values' => array_fill(0, 51, 'x')]]])],
    'a limit that is a word' => [explorerDefinition(['limit' => 'ten'])],
    'a form that is not an id' => [explorerDefinition(['form_id' => 12])],
]);
