<?php

declare(strict_types=1);

use App\Services\Expressions\Ast\FieldReferenceNode;
use App\Services\Expressions\Ast\FunctionCallNode;
use App\Services\Expressions\Ast\LiteralNode;
use App\Services\Expressions\Ast\SelfReferenceNode;
use App\Services\Forms\ExpressionKeyUse;

/*
|--------------------------------------------------------------------------
| M134 — the one walker the conversion census and the publish gate share.
|--------------------------------------------------------------------------
| M134 split its two coarse flags without changing them: `numeric` is exactly `ordering` or `arithmetic`, `list`
| still includes `equality`, and `against` holds the other side of every ordering. ConversionCensus reads only
| `present`, `numeric` and `list`, so the invariants below are what keeps its verdicts byte-identical.
*/

function keyUse(string $expression, string $key, bool $selfIsKey = false): array
{
    return ExpressionKeyUse::of(makeExpressionParser()->parse($expression), $key, $selfIsKey);
}

it('keeps numeric = ordering or arithmetic, and equality inside list, over every shape of use', function (string $expression): void {
    $use = keyUse($expression, 'k', true);

    expect($use['numeric'])->toBe($use['ordering'] || $use['arithmetic'])
        ->and(! $use['equality'] || $use['list'])->toBeTrue()
        ->and($use['against'] !== [])->toBe($use['ordering']);
})->with([
    ['${k} > 3'], ['3 < ${k}'], ['${k} + 1 > 3'], ['int(${k}) = 2'], ["\${k} = 'a'"], ["\${k} != ''"],
    ['count(${k}) > 1'], ["selected(\${k}, 'a')"], ['. >= ${k}'], ['. <= today()'], ["if(\${k} = 'a', 1, 2) > 0"],
    ["not(\${k} = 'a') and \${k} > 2"], ['${other} > 1'],
]);

it('records what each ordering compares the key WITH, on either side and through the self reference', function (): void {
    $right = keyUse('${dob} <= today()', 'dob');
    $left = keyUse("'2020-01-01' < \${dob}", 'dob');
    $self = keyUse('. >= ${start}', 'end', true);
    $other = keyUse('. >= ${start}', 'start');
    $both = keyUse('${dob} > ${a} and ${dob} < 5', 'dob');

    expect($right['against'])->toHaveCount(1)
        ->and($right['against'][0])->toBeInstanceOf(FunctionCallNode::class)
        ->and($left['against'][0])->toBeInstanceOf(LiteralNode::class)
        ->and($self['against'][0])->toBeInstanceOf(FieldReferenceNode::class)
        ->and($other['against'][0])->toBeInstanceOf(SelfReferenceNode::class)
        ->and($both['against'])->toHaveCount(2);
});

it('tells an ordering from arithmetic, and an equality from a membership or a count', function (): void {
    expect(keyUse('${k} > 3', 'k'))->toMatchArray(['ordering' => true, 'arithmetic' => false])
        ->and(keyUse('${k} + 1 = 3', 'k'))->toMatchArray(['ordering' => false, 'arithmetic' => true, 'numeric' => true])
        ->and(keyUse('int(${k}) = 3', 'k'))->toMatchArray(['arithmetic' => true, 'against' => []])
        ->and(keyUse("\${k} = 'a'", 'k'))->toMatchArray(['equality' => true, 'list' => true])
        ->and(keyUse("\${k} != 'a'", 'k'))->toMatchArray(['equality' => true, 'list' => true])
        ->and(keyUse("\${k} = ''", 'k'))->toMatchArray(['present' => true, 'equality' => false, 'list' => false])
        ->and(keyUse('count(${k}) > 1', 'k'))->toMatchArray(['equality' => false, 'list' => true, 'ordering' => false])
        ->and(keyUse("selected(\${k}, 'a')", 'k'))->toMatchArray(['present' => true, 'equality' => false, 'list' => false]);
});
