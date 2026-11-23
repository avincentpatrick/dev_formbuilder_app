<?php

declare(strict_types=1);

use App\Exceptions\Explorer\InvalidExplorerQueryException;
use App\Services\Explorer\Agreement\AgreementItem;
use App\Services\Explorer\Agreement\AgreementShaper;
use App\Support\Explorer\ExplorerAgreement;
use App\Support\Explorer\ExplorerQuery;

/*
|--------------------------------------------------------------------------
| M250 (`agreement-report`, `D234`) — an agreement list's declaration and its rules (ADR-0025 §D10).
|
| No database: the bounds live in the value object's constructor and the rules in a pure shaper, so every number
| that decides a status is written out here. Helpers carry an `agreementUnit` prefix — Pest helpers are global.
|--------------------------------------------------------------------------
*/

/**
 * @param  array<string, mixed>  $agreement
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function agreementUnitDefinition(array $agreement = [], array $overrides = []): array
{
    return [
        'v' => 1,
        'source' => 'agreement',
        'form_id' => '01a20789-56b0-7212-a08e-47d6a87f9dbd',
        'chart' => 'agreement',
        'dimensions' => [],
        'measures' => [],
        'filters' => [],
        'from' => '2026-01-01',
        'to' => '2026-03-31',
        'timezone' => 'Asia/Manila',
        'agreement' => ['items' => ['answer:q1'], 'leave_out' => ['noview'], 'agreeing' => [], 'threshold' => 75, 'min_views' => 6, ...$agreement],
        ...$overrides,
    ];
}

function agreementUnitRefusal(array $definition): string
{
    try {
        ExplorerQuery::fromArray($definition);
    } catch (InvalidExplorerQueryException $e) {
        return $e->reason();
    }

    return 'read';
}

/** @param  array<string, string>  $options */
function agreementUnitItem(string $key, int $position, array $options = ['agree' => 'Agree', 'disagree' => 'Disagree', 'noview' => 'No view'], bool $multiple = false): AgreementItem
{
    return new AgreementItem("answer:{$key}", $key, $key, null, null, ucfirst($key), ucfirst($key), null, 'Section', $position, $options, $multiple);
}

/**
 * The counts the runner reads for one item: each answer's responses, and the item's totals.
 *
 * @param  array<string, int>  $answers
 * @return list<array{n: int, value: string|null, whole: bool, responses: int, views: int, agreeing: int}>
 */
function agreementUnitRows(int $n, array $answers, int $responses, int $views, int $agreeing = 0): array
{
    $rows = [['n' => $n, 'value' => null, 'whole' => true, 'responses' => $responses, 'views' => $views, 'agreeing' => $agreeing]];

    foreach ($answers as $value => $count) {
        $rows[] = ['n' => $n, 'value' => (string) $value, 'whole' => false, 'responses' => $count, 'views' => 0, 'agreeing' => 0];
    }

    return $rows;
}

// ── The declaration ──────────────────────────────────────────────────────────────────────────────────────

it('reads an agreement list back exactly as it was stored, and stores nothing for any other chart', function (): void {
    $query = ExplorerQuery::fromArray(agreementUnitDefinition(['items' => ['answer:q1', 'grid:tor:agree', 'grid:lk'], 'agreeing' => ['agree', 5]]));

    expect($query->toArray()['agreement'])->toBe([
        'items' => ['answer:q1', 'grid:tor:agree', 'grid:lk'], 'leave_out' => ['noview'], 'agreeing' => ['agree', '5'], 'threshold' => 75, 'min_views' => 6,
    ])
        ->and(ExplorerQuery::fromArray($query->toArray())->toArray())->toBe($query->toArray())
        ->and(ExplorerQuery::fromArray([...agreementUnitDefinition(), 'agreement' => null, 'chart' => 'bar', 'source' => 'responses', 'dimensions' => [['field' => 'status']], 'measures' => [['aggregate' => 'count']]])->toArray())
        ->not->toHaveKey('agreement');
});

it('opens with 75% and six views when a stored list leaves both out', function (): void {
    $definition = agreementUnitDefinition();
    unset($definition['agreement']['threshold'], $definition['agreement']['min_views']);

    $query = ExplorerQuery::fromArray($definition);

    expect($query->agreement?->threshold)->toBe(75)->and($query->agreement?->minViews)->toBe(6);
});

it('refuses an agreement list outside its bounds', function (array $agreement, string $reason): void {
    expect(agreementUnitRefusal(agreementUnitDefinition($agreement)))->toBe($reason);
})->with([
    'no question' => [['items' => []], 'chart_shape'],
    'more than 100 picks' => [['items' => array_map(static fn (int $i): string => "answer:q{$i}", range(1, 101))], 'too_many'],
    'the same question twice' => [['items' => ['answer:q1', 'answer:q1']], 'chart_shape'],
    'a pick that is not a key' => [['items' => ["answer:q1'--"]], 'malformed_definition'],
    'a pick of an unknown kind' => [['items' => ['field:q1']], 'malformed_definition'],
    'an answer both left out and agreeing' => [['agreeing' => ['noview']], 'chart_shape'],
    'a threshold below 50' => [['threshold' => 49], 'chart_shape'],
    'a threshold above 100' => [['threshold' => 101], 'chart_shape'],
    'no minimum' => [['min_views' => 0], 'chart_shape'],
    'a minimum above 1000' => [['min_views' => 1001], 'chart_shape'],
    'a threshold that is not a number' => [['threshold' => '75%'], 'malformed_definition'],
    'more than 50 answers left out' => [['leave_out' => array_map(static fn (int $i): string => "v{$i}", range(1, 51))], 'too_many'],
    'an empty answer' => [['leave_out' => ['']], 'malformed_definition'],
    'an answer that is a list' => [['agreeing' => [['agree']]], 'malformed_definition'],
]);

it('lets only an agreement list carry one, and gives it no group-by, value or grid', function (): void {
    expect(agreementUnitRefusal(agreementUnitDefinition([], ['chart' => 'bar', 'dimensions' => [['field' => 'status']], 'measures' => [['aggregate' => 'count']]])))->toBe('malformed_definition')
        ->and(agreementUnitRefusal(agreementUnitDefinition([], ['agreement' => null])))->toBe('chart_shape')
        ->and(agreementUnitRefusal(agreementUnitDefinition([], ['measures' => [['aggregate' => 'count']]])))->toBe('chart_shape')
        ->and(agreementUnitRefusal(agreementUnitDefinition([], ['dimensions' => [['field' => 'status']]])))->toBe('chart_shape')
        ->and(agreementUnitRefusal(agreementUnitDefinition([], ['grid' => 'tor'])))->toBe('chart_shape');
});

it('reads a pick into its question and column', function (): void {
    expect(ExplorerAgreement::parse('answer:q1'))->toBe(['kind' => 'answer', 'key' => 'q1', 'column' => null])
        ->and(ExplorerAgreement::parse('grid:mx_a_table:use'))->toBe(['kind' => 'grid', 'key' => 'mx_a_table', 'column' => 'use'])
        ->and(ExplorerAgreement::parse('grid:lk'))->toBe(['kind' => 'grid', 'key' => 'lk', 'column' => null])
        ->and(ExplorerAgreement::parse('grid:a:b:c'))->toBeNull()
        ->and(ExplorerAgreement::parse('answer:'))->toBeNull();
});

// ── The rules ────────────────────────────────────────────────────────────────────────────────────────────

it('short-lists at exactly the threshold with exactly the minimum views, and not one view fewer', function (): void {
    $spec = new ExplorerAgreement(['answer:a', 'answer:b'], ['noview'], [], 75, 6);
    $lines = (new AgreementShaper)->shape($spec, [agreementUnitItem('a', 0), agreementUnitItem('b', 1)], [
        // a: 6 of 8 views agree, 2 left out — exactly 75% of more than six.
        ...agreementUnitRows(0, ['agree' => 6, 'disagree' => 2, 'noview' => 2], 10, 8),
        // b: 4 of 5 views agree — 80%, but one view short of six.
        ...agreementUnitRows(1, ['agree' => 4, 'disagree' => 1], 5, 5),
    ])['items'];

    expect(array_column($lines, 'status', 'key'))->toBe(['a' => 'short_list', 'b' => 'too_few'])
        ->and($lines[0])->toMatchArray(['views' => 8, 'left_out' => 2, 'agreeing' => 6, 'share' => 75]);
});

it('rounds a share down, so 2 of 3 reads 66.6% and never 66.7%', function (): void {
    $spec = new ExplorerAgreement(['answer:a'], [], [], 67, 1);
    $line = (new AgreementShaper)->shape($spec, [agreementUnitItem('a', 0)], agreementUnitRows(0, ['agree' => 2, 'disagree' => 1], 3, 3))['items'][0];

    expect($line['share'])->toBe(66.6)->and($line['status'])->toBe('discuss');
});

it('orders the short list highest first, the rest lowest first, too few views last, ties in form order', function (): void {
    $spec = new ExplorerAgreement(['answer:a', 'answer:b', 'answer:c', 'answer:d', 'answer:e', 'answer:f'], [], [], 60, 2);
    $items = array_map(static fn (string $key, int $position): AgreementItem => agreementUnitItem($key, $position), ['a', 'b', 'c', 'd', 'e', 'f'], range(0, 5));

    $lines = (new AgreementShaper)->shape($spec, $items, [
        ...agreementUnitRows(0, ['agree' => 2, 'disagree' => 1], 3, 3),   // 66.6% short list
        ...agreementUnitRows(1, ['agree' => 1], 1, 1),                    // too few
        ...agreementUnitRows(2, ['agree' => 3, 'disagree' => 2], 5, 5),   // 60%  short list
        ...agreementUnitRows(3, ['agree' => 1, 'disagree' => 1], 2, 2),   // 50%  discuss
        ...agreementUnitRows(4, ['agree' => 2, 'disagree' => 2], 4, 4),   // 50%  discuss, after d in the form
        ...agreementUnitRows(5, ['agree' => 4, 'disagree' => 3], 7, 7),   // 57.1% discuss
    ]);

    expect(array_column($lines['items'], 'key'))->toBe(['a', 'c', 'd', 'e', 'f', 'b'])
        ->and($lines['summary'])->toBe(['items' => 6, 'short_list' => 2, 'discuss' => 3, 'too_few' => 1]);
});

it('counts the marked answers only on an item that offers one, and names them with their labels', function (): void {
    $spec = new ExplorerAgreement(['answer:s', 'answer:c'], ['noview'], ['4', '5'], 75, 1);
    $scale = agreementUnitItem('s', 0, ['1' => 'Strongly disagree', '4' => 'Agree', '5' => 'Strongly agree']);
    $choice = agreementUnitItem('c', 1, ['a' => 'A', 'b' => 'B']);

    $out = (new AgreementShaper)->shape($spec, [$scale, $choice], [
        ...agreementUnitRows(0, ['1' => 1, '4' => 2, '5' => 1], 4, 4, 3),
        ...agreementUnitRows(1, ['a' => 1, 'b' => 3], 4, 4),
    ]);
    $lines = array_column($out['items'], null, 'key');

    expect($lines['s'])->toMatchArray(['basis' => 'agreeing', 'agreeing' => 3, 'share' => 75, 'status' => 'short_list'])
        ->and($lines['s']['counted'])->toBe([['value' => '4', 'label' => 'Agree'], ['value' => '5', 'label' => 'Strongly agree']])
        ->and($lines['c'])->toMatchArray(['basis' => 'most_common', 'agreeing' => 3, 'share' => 75])
        ->and($lines['c']['counted'])->toBe([['value' => 'b', 'label' => 'B']])
        ->and($out['agreeing'])->toBe([['value' => '4', 'label' => 'Agree'], ['value' => '5', 'label' => 'Strongly agree']])
        ->and($out['leave_out'])->toBe([['value' => 'noview', 'label' => 'noview']]);
});

it('never takes an answer left out as the most common, and lists an answer the author never offered after the offered ones', function (): void {
    $spec = new ExplorerAgreement(['answer:a'], ['noview'], [], 75, 1);
    $line = (new AgreementShaper)->shape($spec, [agreementUnitItem('a', 0)], agreementUnitRows(0, ['noview' => 5, 'agree' => 1, 'retired' => 2], 8, 3))['items'][0];

    expect($line['counted'])->toBe([['value' => 'retired', 'label' => 'retired']])
        ->and(array_column($line['answers'], 'value'))->toBe(['agree', 'disagree', 'noview', 'retired'])
        ->and($line['share'])->toBe(66.6);
});
