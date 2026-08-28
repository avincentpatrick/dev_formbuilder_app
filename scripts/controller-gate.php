<?php

declare(strict_types=1);

/*
 * Thin-controller gate (docs/testing-strategy.md §4).
 *
 * Fails CI when a controller class exceeds 250 CODE lines (blank lines and comment/docblock
 * lines excluded) OR when any method exceeds cyclomatic complexity 10. Tooling-enforced, not
 * review convention — guards against the legacy god-controllers (FormController.php was 1,747
 * lines). Standalone (nikic/php-parser + token_get_all); replaces PHPMD, which is fatally
 * incompatible with PHP 8.4 / Symfony 7.
 *
 * Usage: php scripts/controller-gate.php
 */

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

require __DIR__.'/../vendor/autoload.php';

const MAX_CLASS_CODE_LINES = 250;
const MAX_METHOD_COMPLEXITY = 10;

$root = dirname(__DIR__);
$targets = [$root.'/app/Http/Controllers'];

$parser = (new ParserFactory)->createForNewestSupportedVersion();
$finder = new NodeFinder;

/**
 * A plausible floor for the discovery check below (M36).
 *
 * The tree held 97 controller file(s) when this floor was set; it sits well below that so ordinary deletion does
 * not trip it, and well above zero so a broken or renamed scan root does.
 *
 * Measured rather than guessed: this gate run INSIDE the app container on this host reports
 * 49, because RecursiveDirectoryIterator descends the Windows bind mount only partially while
 * `find` on the same path sees every file. An under-scan here is a reproducible event today,
 * not a hypothetical one — which is why the floor is a hard failure and not a warning.
 */
const MIN_EXPECTED_CONTROLLERS = 55;

$violations = [];
$scanned = 0;

foreach ($targets as $dir) {
    if (! is_dir($dir)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $scanned++;
        $code = (string) file_get_contents($file->getPathname());
        $relative = substr($file->getPathname(), strlen($root) + 1);

        $ast = $parser->parse($code);
        if ($ast === null) {
            continue;
        }

        $codeLines = code_line_numbers($code);

        foreach ($finder->findInstanceOf($ast, Class_::class) as $class) {
            $name = $class->name?->toString() ?? '(anonymous)';
            $start = (int) $class->getStartLine();
            $end = (int) $class->getEndLine();

            $length = count(array_filter(
                $codeLines,
                static fn (int $line): bool => $line >= $start && $line <= $end
            ));

            if ($length > MAX_CLASS_CODE_LINES) {
                $violations[] = sprintf(
                    '%s: class %s is %d code lines (max %d).',
                    $relative, $name, $length, MAX_CLASS_CODE_LINES
                );
            }

            foreach ($finder->findInstanceOf($class->stmts, ClassMethod::class) as $method) {
                $complexity = cyclomatic_complexity($method, $finder);

                if ($complexity > MAX_METHOD_COMPLEXITY) {
                    $violations[] = sprintf(
                        '%s: method %s::%s() has cyclomatic complexity %d (max %d).',
                        $relative, $name, $method->name->toString(), $complexity, MAX_METHOD_COMPLEXITY
                    );
                }
            }
        }
    }
}

if ($scanned < MIN_EXPECTED_CONTROLLERS && $violations === []) {
    // A gate nobody can tell is blind is a gate nobody is running. Mirrors R2 in
    // scripts/component-import-lint.php, which was the only one of the five gates to have a floor.
    fwrite(STDERR, sprintf(
        "Controller gate FAILED: scanned only %d controller file(s), expected at least %d.
".
        "  This is a DISCOVERY regression, not a clean run — a scan root has moved, or the gate is
".
        "  running somewhere its iterator cannot see the whole tree. Run it on the HOST, not inside
".
        "  the app container — on this host the container reports 49 of 97.
",
        $scanned,
        MIN_EXPECTED_CONTROLLERS
    ));
    exit(1);
}

if ($violations !== []) {
    fwrite(STDERR, "Controller gate FAILED:\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, "  - {$violation}\n");
    }
    exit(1);
}

fwrite(STDOUT, sprintf("Controller gate passed (%d file(s) scanned).\n", $scanned));
exit(0);

/**
 * Line numbers containing at least one non-comment, non-whitespace token.
 *
 * @return list<int>
 */
function code_line_numbers(string $code): array
{
    $lines = [];
    $current = 1;
    $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML];

    foreach (token_get_all($code) as $token) {
        if (is_array($token)) {
            [$id, $text, $line] = $token;
            $newlines = substr_count($text, "\n");

            if (! in_array($id, $skip, true)) {
                for ($i = 0; $i <= $newlines; $i++) {
                    $lines[$line + $i] = true;
                }
            }

            $current = $line + $newlines;
        } else {
            // Single-char token (`{`, `}`, `;`, `(` …) — structural code on the current line.
            $lines[$current] = true;
        }
    }

    return array_map('intval', array_keys($lines));
}

/**
 * McCabe cyclomatic complexity: 1 + each decision point in the method body.
 */
function cyclomatic_complexity(ClassMethod $method, NodeFinder $finder): int
{
    if ($method->stmts === null) {
        return 1; // abstract / interface method
    }

    $decisions = $finder->find($method->stmts, static function (Node $node): bool {
        return $node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\ElseIf_
            || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\Do_
            || $node instanceof Node\Stmt\Catch_
            || $node instanceof Node\Expr\BinaryOp\BooleanAnd
            || $node instanceof Node\Expr\BinaryOp\BooleanOr
            || $node instanceof Node\Expr\BinaryOp\Coalesce
            || $node instanceof Node\Expr\Ternary
            || ($node instanceof Node\Stmt\Case_ && $node->cond !== null)   // skip `default:`
            || ($node instanceof Node\MatchArm && $node->conds !== null);   // skip default arm
    });

    return 1 + count($decisions);
}
