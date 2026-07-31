<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Exceptions\Templates\TemplateSyntaxException;
use App\Services\Submissions\SchemaValueFormatter;
use App\Services\Templates\TemplateParser;
use App\Services\Templates\TemplateRenderer;

/*
|--------------------------------------------------------------------------
| Template golden-vector runner (Increment H6a — the drift contract, Risk R3).
|--------------------------------------------------------------------------
| Pure Pest Unit (no DB, no container). Iterates the language-neutral fixtures under
| tests/golden/templates/*.json — the SAME files the TypeScript twin runs
| (resources/public-runtime/engine/__tests__/golden-templates.test.ts) — so drift between the two
| template parsers is a CI failure, not a silent UX inconsistency. Paths resolve via
| dirname(__DIR__, 2) (the Unit suite does not boot the framework, so base_path() is unavailable).
|
| THE THIRD corpus, and the third pair of runners. Pest loads every test file into ONE process, so every
| top-level function here is prefixed: `goldenDir`/`buildExpression`/`loadVectorFiles` belong to
| GoldenVectorsTest.php and `semanticGoldenDir`/`loadSemanticVectorFiles` to SemanticGoldenVectorsTest.php.
| Reusing any of those names is a PHP fatal, not a test failure.
|
| Vectors assert TemplateParser::TEMPLATE_VERSION, never ExpressionEvaluator::GRAMMAR_VERSION. The
| validation corpus asserts against GRAMMAR_VERSION only because it has no constant of its own; extending
| that habit here would couple the sibling grammar to the expression grammar, which is exactly what
| Doc #26 §2 was written to prevent.
|
| Two modes (`mode`, default 'parse'):
|   parse  — the template's segment list, or its `expected_error` slug. Runs on BOTH engines.
|   render — the filled string. PHP-ONLY (`engines: ["php"]`), because rendering a hole means running
|            SchemaValueFormatter::displayValue(), which has no TypeScript twin yet — Doc #26 §3.2
|            assigns building one to H6b, and that twin is the real R3 hazard in this feature, not the
|            parser. These vectors pin the PHP side NOW (displayValue() has no direct unit test at all
|            today) so H6b has something to mirror rather than something to invent.
|
| NOTE: PHP 8.3 removed `${var}` string interpolation, so every `${` literal in this file and in a vector
| read from JSON must survive as text. JSON is safe; PHP literals here are SINGLE-quoted.
*/

function templateGoldenDir(): string
{
    return dirname(__DIR__, 2).'/golden/templates';
}

/**
 * Build a vector's input: either a literal `template`, or a generated one via `template_repeat`
 * (the `expression_repeat` device, renamed so the two corpora's optional-key vocabularies cannot collide).
 *
 * @param  array<string, mixed>  $case
 */
function buildTemplate(array $case): string
{
    if (array_key_exists('template_repeat', $case)) {
        /** @var array{prefix: string, times: int, core: string, suffix: string} $repeat */
        $repeat = $case['template_repeat'];

        return str_repeat($repeat['prefix'], $repeat['times']).$repeat['core'].str_repeat($repeat['suffix'], $repeat['times']);
    }

    /** @var string $template */
    $template = $case['template'];

    return $template;
}

/**
 * A vector's expected segment list. Generated vectors declare it compactly:
 *   `expected_whole_literal: true`      → the whole built template comes back as one literal segment
 *   `expected_repeat: {times, segment}` → N copies of one segment
 *
 * @param  array<string, mixed>  $case
 * @return list<array{type: string, value: string}>
 */
function expectedTemplateSegments(array $case, string $template): array
{
    if (array_key_exists('expected_whole_literal', $case)) {
        return [['type' => 'literal', 'value' => $template]];
    }

    if (array_key_exists('expected_repeat', $case)) {
        /** @var array{times: int, segment: array{type: string, value: string}} $repeat */
        $repeat = $case['expected_repeat'];

        return array_fill(0, $repeat['times'], $repeat['segment']);
    }

    /** @var list<array{type: string, value: string}> $expected */
    $expected = $case['expected'];

    return $expected;
}

/**
 * @return list<array<string, mixed>>
 */
function loadTemplateVectorFiles(): array
{
    $out = [];

    foreach (glob(templateGoldenDir().'/*.json') ?: [] as $file) {
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

/** Hand-wired renderer — the Unit suite has no container. */
function makeTemplateRenderer(): TemplateRenderer
{
    return new TemplateRenderer(new TemplateParser, new SchemaValueFormatter);
}

/**
 * A render vector's `sources` map, JSON `{key: {type, config}}` → the renderer's `{key: {FieldType, array}}`.
 *
 * @param  array<string, mixed>  $raw
 * @return array<string, array{type: FieldType, config: array<string, mixed>}>
 */
function templateRenderSources(array $raw): array
{
    $sources = [];

    foreach ($raw as $key => $source) {
        /** @var array{type: string, config: array<string, mixed>} $source */
        $sources[(string) $key] = [
            'type' => FieldType::from($source['type']),
            'config' => $source['config'],
        ];
    }

    return $sources;
}

dataset('template vectors', function (): iterable {
    foreach (loadTemplateVectorFiles() as $case) {
        /** @var string $name */
        $name = $case['name'];
        yield $name => [$case];
    }
});

it('matches the golden template vector', function (array $case): void {
    expect($case['template_version'])->toBe(TemplateParser::TEMPLATE_VERSION);

    $template = buildTemplate($case);

    if (array_key_exists('expected_error', $case)) {
        try {
            (new TemplateParser)->parse($template);
            $this->fail("expected error “{$case['expected_error']}” but no exception was thrown for: {$template}");
        } catch (TemplateSyntaxException $e) {
            expect($e->slug())->toBe($case['expected_error']);
        }

        return;
    }

    if (($case['mode'] ?? 'parse') === 'render') {
        /** @var array<string, mixed> $rawSources */
        $rawSources = $case['sources'];
        /** @var array<string, mixed> $answers */
        $answers = $case['answers'];

        $rendered = makeTemplateRenderer()->render($template, templateRenderSources($rawSources), $answers);

        expect($rendered)->toBe($case['expected']);

        return;
    }

    expect((new TemplateParser)->parse($template))->toBe(expectedTemplateSegments($case, $template));
})->with('template vectors');

it('enforces the template manifest count-parity, per-file, and name-uniqueness guards', function (): void {
    /** @var array{template_version: string, total: int, files: array<string, int>} $manifest */
    $manifest = json_decode((string) file_get_contents(templateGoldenDir().'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['template_version'])->toBe(TemplateParser::TEMPLATE_VERSION);

    $perFile = [];
    $names = [];
    $loaded = 0;

    foreach (glob(templateGoldenDir().'/*.json') ?: [] as $file) {
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

it('requires every vector to carry an assertion', function (): void {
    // The existing runners default optional keys with `?? []`, which lets a vector that asserts NOTHING
    // pass vacuously. This corpus refuses that: every vector must declare exactly one outcome.
    foreach (loadTemplateVectorFiles() as $case) {
        $outcomes = array_filter([
            array_key_exists('expected', $case),
            array_key_exists('expected_error', $case),
            array_key_exists('expected_whole_literal', $case),
            array_key_exists('expected_repeat', $case),
        ]);

        expect($outcomes)->toHaveCount(1, "vector “{$case['name']}” must declare exactly one expected outcome");
    }
});
