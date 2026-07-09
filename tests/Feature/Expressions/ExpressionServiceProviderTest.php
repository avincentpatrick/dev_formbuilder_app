<?php

declare(strict_types=1);

use App\Services\Expressions\EvaluationContext;
use App\Services\Expressions\ExpressionEvaluator;

/*
|--------------------------------------------------------------------------
| Provider wiring smoke test (Increment F2).
|--------------------------------------------------------------------------
| The golden runner hand-constructs the engine, so it would not catch a broken binding. This Feature
| test boots the container and proves ExpressionServiceProvider resolves the evaluator as a shared
| singleton (so the parser memo survives the request).
*/

it('resolves the evaluator from the container and evaluates', function (): void {
    $evaluator = app(ExpressionEvaluator::class);

    expect($evaluator->evaluate('${a} = 1', new EvaluationContext(['a' => 1])))->toBeTrue();
});

it('binds the evaluator as a singleton', function (): void {
    expect(app(ExpressionEvaluator::class))->toBe(app(ExpressionEvaluator::class));
});
