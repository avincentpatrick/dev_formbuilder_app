<?php

declare(strict_types=1);

use App\Services\Expressions\AstBuilders;
use App\Services\Expressions\Coercion;
use App\Services\Expressions\EvaluationContext;
use App\Services\Expressions\Marker;

it('pins toBool across the boundary set (numeric checked before the string set)', function (): void {
    expect(Coercion::toBool(Marker::Absent))->toBeFalse();
    expect(Coercion::toBool(null))->toBeFalse();
    expect(Coercion::toBool(''))->toBeFalse();
    expect(Coercion::toBool('0'))->toBeFalse();
    expect(Coercion::toBool('0.0'))->toBeFalse();   // numeric-before-string ordering
    expect(Coercion::toBool('00'))->toBeFalse();    // numeric-before-string ordering
    expect(Coercion::toBool('false'))->toBeFalse();
    expect(Coercion::toBool(0))->toBeFalse();
    expect(Coercion::toBool([]))->toBeFalse();
    expect(Coercion::toBool(' '))->toBeTrue();       // non-empty non-numeric string
    expect(Coercion::toBool('yes'))->toBeTrue();
    expect(Coercion::toBool(5))->toBeTrue();
    expect(Coercion::toBool(['x']))->toBeTrue();
});

it('pins toNumber: only anchored decimals are numeric, everything else is NaN', function (): void {
    expect(Coercion::toNumber('20'))->toBe(20.0);
    expect(Coercion::toNumber(20))->toBe(20.0);
    expect(is_nan(Coercion::toNumber('')))->toBeTrue();
    expect(is_nan(Coercion::toNumber(' 5 ')))->toBeTrue();
    expect(is_nan(Coercion::toNumber('1e3')))->toBeTrue();
    expect(is_nan(Coercion::toNumber('0x1F')))->toBeTrue();
    expect(is_nan(Coercion::toNumber('+5')))->toBeTrue();
    expect(is_nan(Coercion::toNumber(Marker::Absent)))->toBeTrue();
});

it('pins isEmpty and isNumericLike and toStr', function (): void {
    expect(Coercion::isEmpty(Marker::Absent))->toBeTrue();
    expect(Coercion::isEmpty(null))->toBeTrue();
    expect(Coercion::isEmpty(''))->toBeTrue();
    expect(Coercion::isEmpty([]))->toBeTrue();
    expect(Coercion::isEmpty('x'))->toBeFalse();
    expect(Coercion::isEmpty(0))->toBeFalse();

    expect(Coercion::isNumericLike('007'))->toBeTrue();
    expect(Coercion::isNumericLike('5.'))->toBeFalse();
    expect(Coercion::isNumericLike('.5'))->toBeFalse();
    expect(Coercion::isNumericLike(5.0))->toBeTrue();

    expect(Coercion::toStr(20))->toBe('20');
    expect(Coercion::toStr(5.0))->toBe('5');        // integral float has no fractional part
    expect(Coercion::toStr(true))->toBe('1');       // PHP cast, not JS String(bool)
    expect(Coercion::toStr(false))->toBe('');
    expect(Coercion::toStr(Marker::Absent))->toBe('');
});

it('evaluates the internal contains node (never parseable): membership then substring', function (): void {
    $engine = makeExpressionEvaluator();
    $node = AstBuilders::contains('tags', 'x');

    expect($engine->evaluateNode($node, new EvaluationContext(['tags' => ['x', 'y']])))->toBeTrue();  // array membership
    expect($engine->evaluateNode($node, new EvaluationContext(['tags' => ['y', 'z']])))->toBeFalse();
    expect($engine->evaluateNode($node, new EvaluationContext(['tags' => 'axb'])))->toBeTrue();        // scalar substring
    expect($engine->evaluateNode($node, new EvaluationContext(['tags' => 'nope'])))->toBeFalse();
    expect($engine->evaluateNode($node, new EvaluationContext([])))->toBeFalse();                       // absent → empty → false
});

it('resolves an unset self-reference to empty rather than throwing', function (): void {
    $engine = makeExpressionEvaluator();

    expect($engine->evaluateBoolean(". = ''", new EvaluationContext([])))->toBeTrue();
    expect($engine->evaluateBoolean('. > 0', new EvaluationContext([], 5)))->toBeTrue();
});

it('never throws on data (a non-numeric operand simply yields false)', function (): void {
    $engine = makeExpressionEvaluator();

    expect($engine->evaluateBoolean('${x} > 5', new EvaluationContext(['x' => 'abc'])))->toBeFalse();
    expect($engine->evaluateBoolean('${x} > 5', new EvaluationContext(['x' => null])))->toBeFalse();
});

it('short-circuits and/or to the correct result', function (): void {
    $engine = makeExpressionEvaluator();

    // Left decides the result; the right operand references an absent field either way.
    expect($engine->evaluateBoolean('${a} = 1 and ${b} = 2', new EvaluationContext(['a' => 9])))->toBeFalse();
    expect($engine->evaluateBoolean('${a} = 1 or ${b} = 2', new EvaluationContext(['a' => 1])))->toBeTrue();
});
