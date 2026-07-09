<?php

declare(strict_types=1);

use App\Exceptions\Expressions\ExpressionSyntaxException;
use App\Services\Expressions\EvaluationContext;
use App\Services\Expressions\ExpressionEvaluator;

/*
|--------------------------------------------------------------------------
| Golden-vector runner (Increment F2 — the drift contract, Risk R3).
|--------------------------------------------------------------------------
| Pure Pest Unit (no DB, no container). Iterates the language-neutral fixtures under
| tests/golden/expressions/*.json — the SAME files the Phase-1 TypeScript engine will run — so drift
| between the two engines is a CI failure, not a silent UX inconsistency. Paths resolve via
| dirname(__DIR__, 2) (the Unit suite does not boot the framework, so base_path() is unavailable).
*/

function goldenDir(): string
{
    return dirname(__DIR__, 2).'/golden/expressions';
}

/**
 * @param  array<string, mixed>  $case
 */
function buildExpression(array $case): string
{
    if (array_key_exists('expression_repeat', $case)) {
        /** @var array{prefix: string, times: int, core: string, suffix: string} $repeat */
        $repeat = $case['expression_repeat'];

        return str_repeat($repeat['prefix'], $repeat['times']).$repeat['core'].str_repeat($repeat['suffix'], $repeat['times']);
    }

    /** @var string $expression */
    $expression = $case['expression'];

    return $expression;
}

/**
 * @return list<array<string, mixed>>
 */
function loadVectorFiles(): array
{
    $out = [];

    foreach (glob(goldenDir().'/*.json') ?: [] as $file) {
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

dataset('expression vectors', function (): iterable {
    foreach (loadVectorFiles() as $case) {
        /** @var string $name */
        $name = $case['name'];
        yield $name => [$case];
    }
});

it('matches the golden vector', function (array $case): void {
    expect($case['grammar_version'])->toBe(ExpressionEvaluator::GRAMMAR_VERSION);

    $engine = makeExpressionEvaluator();
    /** @var array{answers?: array<string, mixed>, self?: mixed} $context */
    $context = $case['context'];
    $evaluationContext = EvaluationContext::fromVector($context);
    $expression = buildExpression($case);

    if (array_key_exists('expected_error', $case)) {
        try {
            $engine->evaluate($expression, $evaluationContext);
            $this->fail("expected error “{$case['expected_error']}” but no exception was thrown for: {$expression}");
        } catch (ExpressionSyntaxException $e) {
            expect($e->slug())->toBe($case['expected_error']);
        }

        return;
    }

    $result = ($case['eval'] ?? 'bool') === 'value'
        ? $engine->evaluate($expression, $evaluationContext)
        : $engine->evaluateBoolean($expression, $evaluationContext);

    expect($result)->toEqual($case['expected']);
})->with('expression vectors');

it('enforces the manifest count-parity, per-file, and name-uniqueness guards', function (): void {
    /** @var array{total: int, files: array<string, int>} $manifest */
    $manifest = json_decode((string) file_get_contents(goldenDir().'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

    $perFile = [];
    $names = [];
    $loaded = 0;

    foreach (glob(goldenDir().'/*.json') ?: [] as $file) {
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

    expect($loaded)->toBe($manifest['total']);                       // a dropped/added vector fails here
    expect($perFile)->toEqual($manifest['files']);                  // a vector moved between files fails here
    expect(count(array_unique($names)))->toBe($manifest['total']);  // a duplicate name fails here
});
