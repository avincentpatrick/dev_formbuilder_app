<?php

declare(strict_types=1);

use App\Exceptions\Templates\TemplateSyntaxException;
use App\Services\Templates\TemplateParser;

// What a language-neutral vector cannot express: the LENIENT mode (§3.4's never-fail render contract),
// the hole-free cap exemption's branch behaviour, and holeKeys()'s ordering contract. Pure Unit — no
// container, no DB.
//
// EVERY `${` literal below is SINGLE-quoted. PHP 8.3 removed `${var}` string interpolation, so a
// double-quoted literal loses its holes before the parser sees them and the assertion silently tests
// nothing (the standing gotcha, institutionalised in ExpressionLexerTest).

it('never throws in lenient mode, for any input the strict mode rejects', function (): void {
    $parser = new TemplateParser;

    $malformed = ['${', '${}', '${1abc}', '${a-b}', '${a b}', 'Age of ${child_name', '${a', '${.}', '${$}'];

    foreach ($malformed as $template) {
        expect(fn () => $parser->parse($template))->toThrow(TemplateSyntaxException::class);

        // The same input in lenient mode yields segments and no exception — a question with a gap in it is
        // recoverable, a 500 on a respondent's form is not.
        expect($parser->parseLenient($template))->toBeArray();
    }
});

it('drops a malformed hole and resumes scanning past it', function (): void {
    // The malformed `${` is consumed, its residue stays literal, and a well-formed hole AFTER it is still
    // recognised — one bad hole must not swallow the rest of the string.
    expect((new TemplateParser)->parseLenient('a ${1bad} b ${good} c'))->toBe([
        ['type' => 'literal', 'value' => 'a 1bad} b '],
        ['type' => 'hole', 'value' => 'good'],
        ['type' => 'literal', 'value' => ' c'],
    ]);
});

it('exempts a hole-free value from both caps but applies them once a hole appears', function (): void {
    $parser = new TemplateParser;

    // Doc #26 §2.1 as amended by H6a. `hint` and `description` are TEXT columns that XLSForm import and
    // the blueprint materializer write with no FormRequest in the path, so an over-long value can predate
    // piping entirely — capping it unconditionally would refuse to publish a form for a value piping never
    // touches. The caps exist to bound RENDER cost, and a hole-free template has none.
    $long = str_repeat('x', 3000);

    expect($parser->parse($long))->toBe([['type' => 'literal', 'value' => $long]]);

    // Add one hole and the same length is refused.
    expect(fn () => $parser->parse(str_repeat('y', 1998).'${a}'))
        ->toThrow(TemplateSyntaxException::class);

    // An escape counts as hole-bearing: `$${` contains `${`, so the early return does not fire.
    expect(fn () => $parser->parse(str_repeat('y', 1998).'$${'))
        ->toThrow(TemplateSyntaxException::class);
});

it('caps holes at twenty, not nineteen or twenty-one', function (): void {
    $parser = new TemplateParser;

    expect($parser->parse(str_repeat('${a}', 20)))->toHaveCount(20);

    try {
        $parser->parse(str_repeat('${a}', 21));
        expect(false)->toBeTrue('21 holes must be refused');
    } catch (TemplateSyntaxException $e) {
        expect($e->slug())->toBe('too_many_holes');
    }
});

it('returns distinct hole keys in first-seen order', function (): void {
    // Mirrors ExpressionParser::referencedKeys()'s contract, which the publish gate walks.
    expect((new TemplateParser)->holeKeys('${b} ${a} ${b} ${c}'))->toBe(['b', 'a', 'c']);
});

it('separates the two cap slugs rather than overloading one', function (): void {
    // ExpressionLexer raises `expression_too_long` for BOTH its length cap and its token cap, passing the
    // byte length in either case — so a short, token-heavy expression reports a misleading number. The
    // template grammar does not replicate that: two caps, two slugs.
    try {
        (new TemplateParser)->parse(str_repeat('y', 1998).'${a}');
        expect(false)->toBeTrue('the byte cap must be refused');
    } catch (TemplateSyntaxException $e) {
        expect($e->slug())->toBe('template_too_long');
    }
});
