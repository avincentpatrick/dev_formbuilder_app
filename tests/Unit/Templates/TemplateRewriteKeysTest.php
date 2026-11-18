<?php

declare(strict_types=1);

use App\Services\Templates\TemplateParser;

// M240 (`builder-copy-section`) — TemplateParser::rewriteKeys(), the piping half of a copy's re-pointing. Pure Unit.
//
// EVERY `${` literal below is SINGLE-quoted (TemplateParserTest's note: a double-quoted one loses its holes in PHP 8.3).

it('re-points every hole the map names and keeps the rest of the text', function (): void {
    expect((new TemplateParser)->rewriteKeys('Why ${a01_keep}? Cut: ${a01_cut}; again ${a01_keep}.', ['a01_keep' => 'a02_keep', 'a01_cut' => 'a02_cut']))
        ->toBe('Why ${a02_keep}? Cut: ${a02_cut}; again ${a02_keep}.');
});

it('keeps an escape, a lone dollar and a malformed hole byte for byte', function (): void {
    // `$${a}` is the escape for the literal text "${a}" — never a hole, so never re-pointed.
    expect((new TemplateParser)->rewriteKeys('$5 for $${a}, ${a} and ${1bad} or ${a', ['a' => 'b']))
        ->toBe('$5 for $${a}, ${b} and ${1bad} or ${a');
});

it('leaves a key the map does not name, and changes nothing for an empty map or hole-free text', function (): void {
    $parser = new TemplateParser;

    expect($parser->rewriteKeys('${a} and ${outside}', ['a' => 'b']))->toBe('${b} and ${outside}')
        ->and($parser->rewriteKeys('${a}', []))->toBe('${a}')
        ->and($parser->rewriteKeys('No holes here', ['a' => 'b']))->toBe('No holes here');
});

it('re-points past the caps the strict parse enforces, because it reads leniently', function (): void {
    $template = str_repeat('${a} ', 25);

    expect((new TemplateParser)->rewriteKeys($template, ['a' => 'b']))->toBe(str_repeat('${b} ', 25));
});

it('M246: re-points a grid cell hole by its key and keeps its path', function (): void {
    expect((new TemplateParser)->rewriteKeys('You kept ${g.a01.keep}; score ${lk.r1}; ${g}', ['g' => 'grid2', 'lk' => 'l']))
        ->toBe('You kept ${grid2.a01.keep}; score ${l.r1}; ${grid2}');
});
