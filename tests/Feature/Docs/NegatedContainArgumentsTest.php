<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| No negated `toContain` with more than one argument (M232).
|--------------------------------------------------------------------------
| ⛔ PEST'S `toContain(mixed ...$needles)` TAKES NO MESSAGE. Read in `vendor/` at v4.7.4: every argument is a
| needle. So `expect($x)->not->toContain($needle, 'a message')` asks for the value to lack BOTH needles together,
| and the inner `toContain` throws on the message, which is never there. The negation then passes whatever `$x`
| holds. It cannot fail, and it reads exactly like a ban that can.
|
| Found by `M232` when a mutant it had just built a test for survived it: the test's own fence check was
| `->not->toContain(false, '…')`. A census of every PHP file under `tests/` then found three more, in
| `NpmAuditJudgeTest` (the `--audit-level` ban, unable to fail since `M72`), `DeploySkipAllowlistTest` and
| `OcrCompatibilityBackfillTest`. Neither of the last two hid a violation, measured, and all four now judge a
| boolean with `toBeFalse($message)`.
|
| ⚠️ A TOKENIZER, NOT A REGEX: a comment or a string may name the call to explain it, as this header does, and
| a gate reads code. `toContainEqual` is the only other variadic expectation, so it is held to the same rule.
| ⚠️ Positive calls with several arguments are left alone. There every needle must be present, so a message
| turns the test red instead of silencing it.
| ⚠️ The files come from `SourceTree`, because SPL truncates over the container's bind mount.
| ⚠️ Helpers are prefixed `negatedContain*`: Pest loads every file in a directory into one process.
*/

use Tests\Support\SourceTree;

/**
 * The line of every negated `toContain` / `toContainEqual` call in `$source` that passes more than one argument.
 *
 * @return list<int>
 */
function negatedContainMultiArgumentCalls(string $source): array
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    $lines = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], ['toContain', 'toContainEqual'], true)) {
            continue;
        }

        // Reached through `not ->`, with any whitespace or comment between.
        $j = negatedContainPrevious($tokens, $i, $skip);
        $k = $j === null ? null : negatedContainPrevious($tokens, $j, $skip);

        $negated = $j !== null && $k !== null
            && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && is_array($tokens[$k]) && $tokens[$k][0] === T_STRING && $tokens[$k][1] === 'not';

        if (! $negated) {
            continue;
        }

        // Count the arguments that hold anything, so a trailing comma is not an argument.
        $open = $i + 1;

        while ($open < $count && is_array($tokens[$open]) && in_array($tokens[$open][0], $skip, true)) {
            $open++;
        }

        if (($tokens[$open] ?? null) !== '(') {
            continue;
        }

        $depth = 0;
        $arguments = 0;
        $holds = false;

        for ($q = $open; $q < $count; $q++) {
            $part = $tokens[$q];
            $text = is_array($part) ? $part[1] : $part;
            $opens = in_array($text, ['(', '[', '{'], true)
                || (is_array($part) && in_array($part[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));

            if ($opens) {
                $depth++;

                if ($depth > 1) {
                    $holds = true;
                }

                continue;
            }

            if (in_array($text, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0) {
                    $arguments += $holds ? 1 : 0;

                    break;
                }

                continue;
            }

            if ($text === ',' && $depth === 1) {
                $arguments += $holds ? 1 : 0;
                $holds = false;

                continue;
            }

            if (! is_array($part) || ! in_array($part[0], $skip, true)) {
                $holds = true;
            }
        }

        if ($arguments > 1) {
            $lines[] = $token[2];
        }
    }

    return $lines;
}

/**
 * The index of the nearest token before `$index` that is not whitespace or a comment, or null.
 *
 * @param  array<int, mixed>  $tokens
 * @param  list<int>  $skip
 */
function negatedContainPrevious(array $tokens, int $index, array $skip): ?int
{
    for ($p = $index - 1; $p >= 0; $p--) {
        if (! is_array($tokens[$p]) || ! in_array($tokens[$p][0], $skip, true)) {
            return $p;
        }
    }

    return null;
}

it('sees a negated toContain given a message, and passes the forms that can fail', function (): void {
    // The positive control: a sweep that finds nothing proves nothing until it is shown finding one.
    $fixture = <<<'PHP'
        <?php
        expect($a)->not->toContain('x', 'a message');
        expect($b)->not->toContain('y');
        expect($c)->toContain('p', 'q');
        expect($d)->not->toContainEqual(1, 2);
        expect($e)->not
            ->toContain('z', 'spread over two lines');
        expect($f)->not->toContain(['a', 'b'], );
        expect($g)->not->toContain("{$h->value}, still one argument");
        // expect($i)->not->toContain('in a comment', 'is not code');
        PHP;

    expect(negatedContainMultiArgumentCalls($fixture))->toBe([2, 5, 7]);
});

it('finds no negated toContain or toContainEqual with more than one argument anywhere under tests/', function (): void {
    $root = base_path();
    $hits = [];

    foreach (SourceTree::filesUnder(base_path('tests')) as $path) {
        foreach (negatedContainMultiArgumentCalls((string) file_get_contents($path)) as $line) {
            $hits[] = str_replace([$root.DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path).':'.$line;
        }
    }

    expect($hits)->toBe(
        [],
        "Pest's toContain takes no message: a second argument is a second needle, and under ->not-> it makes ".
        'the assertion unable to fail. Judge a boolean instead, as in '.
        "expect(str_contains(\$haystack, \$needle))->toBeFalse(\$message). Found:\n  ".implode("\n  ", $hits)
    );
});
