<?php

declare(strict_types=1);

use App\Services\Expressions\ExpressionKeyRewriter;

// M240 (`builder-copy-section`) — the expression half of a copy's re-pointing. Pure Unit: no container, no DB.
//
// EVERY `${` literal below is SINGLE-quoted. PHP 8.3 removed `${var}` interpolation, so a double-quoted literal loses
// its references before the rewriter sees them and the assertion silently tests nothing (ExpressionLexerTest's gotcha).

it('re-points every reference the map names, in place, and keeps every other byte', function (): void {
    $rewriter = new ExpressionKeyRewriter;
    $map = ['a01_cut' => 'a02_cut', 'a01_keep' => 'a02_keep'];

    expect($rewriter->rewrite('${a01_cut} = \'change\' or ${a01_keep}  =  \'close\'', $map))
        ->toBe('${a02_cut} = \'change\' or ${a02_keep}  =  \'close\'')
        ->and($rewriter->rewrite('selected(${a01_keep}, \'yes\') and count(${a01_cut}) > 0', $map))
        ->toBe('selected(${a02_keep}, \'yes\') and count(${a02_cut}) > 0');
});

it('re-points a reference to a section, as count() names a repeat', function (): void {
    expect((new ExpressionKeyRewriter)->rewrite('count(${household}) >= 1', ['household' => 'household_2']))
        ->toBe('count(${household_2}) >= 1');
});

it('leaves a key the map does not name, the self-reference and a quoted lookalike untouched', function (): void {
    $rewriter = new ExpressionKeyRewriter;

    // A string literal has no escapes, so `'${a}'` is a String token, never a reference.
    expect($rewriter->rewrite('${a} = \'${a}\' and ${outside} != . and "${a}" = ${a}', ['a' => 'b']))
        ->toBe('${b} = \'${a}\' and ${outside} != . and "${a}" = ${b}');
});

it('handles keys whose new name is longer or shorter without shifting later references', function (): void {
    expect((new ExpressionKeyRewriter)->rewrite('${q1} + ${q10} + ${q1}', ['q1' => 'q1_copy_long', 'q10' => 'r']))
        ->toBe('${q1_copy_long} + ${r} + ${q1_copy_long}');
});

it('re-points text that reads but does not yet parse, and returns text it cannot read unchanged, saying so', function (): void {
    $rewriter = new ExpressionKeyRewriter;

    // Half-typed in a draft but every token reads: re-pointed, so the copy names its own sibling when finished.
    expect($rewriter->rewriteReporting('${a} = ', ['a' => 'b']))->toBe(['${b} = ', false])
        // Unreadable: the copy is exactly as broken as its source, and the caller is told.
        ->and($rewriter->rewriteReporting('${a} = \'open', ['a' => 'b']))->toBe(['${a} = \'open', true])
        ->and($rewriter->rewriteReporting('${ a } = 1', ['a' => 'b']))->toBe(['${ a } = 1', true]);
});

it('does nothing for null, an empty map or text with no reference', function (): void {
    $rewriter = new ExpressionKeyRewriter;

    expect($rewriter->rewrite(null, ['a' => 'b']))->toBeNull()
        ->and($rewriter->rewrite('${a} = 1', []))->toBe('${a} = 1')
        ->and($rewriter->rewriteReporting('. > 3', ['a' => 'b']))->toBe(['. > 3', false]);
});
