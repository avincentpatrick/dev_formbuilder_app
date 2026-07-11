<?php

declare(strict_types=1);

use App\Exceptions\Expressions\ExpressionSyntaxException;
use App\Services\Expressions\TokenType;

it('tokenizes a comparison into the expected stream', function (): void {
    $tokens = makeExpressionLexer()->tokenize('${age} > 15');

    expect(array_map(fn ($t) => $t->type, $tokens))->toBe([
        TokenType::FieldRef, TokenType::Gt, TokenType::Number, TokenType::Eof,
    ]);
    expect($tokens[0]->lexeme)->toBe('age');   // braces stripped
    expect($tokens[2]->lexeme)->toBe('15');
});

it('reads a quoted string without its delimiters and never lowercases it', function (): void {
    $tokens = makeExpressionLexer()->tokenize("'Female'");

    expect($tokens[0]->type)->toBe(TokenType::String);
    expect($tokens[0]->lexeme)->toBe('Female');
});

it('splits a second dot: 1.2.3 becomes number, self, number', function (): void {
    $tokens = makeExpressionLexer()->tokenize('1.2.3');

    expect(array_map(fn ($t) => [$t->type, $t->lexeme], $tokens))->toBe([
        [TokenType::Number, '1.2'],
        [TokenType::SelfRef, '.'],
        [TokenType::Number, '3'],
        [TokenType::Eof, ''],
    ]);
});

it('emits a minus as its own token (folded into a negative literal by the parser)', function (): void {
    $tokens = makeExpressionLexer()->tokenize('-5');

    expect(array_map(fn ($t) => $t->type, $tokens))->toBe([TokenType::Minus, TokenType::Number, TokenType::Eof]);
});

it('gives the right slug for each malformed lexeme', function (string $source, string $slug): void {
    try {
        makeExpressionLexer()->tokenize($source);
        $this->fail("expected {$slug}");
    } catch (ExpressionSyntaxException $e) {
        expect($e->slug())->toBe($slug);
    }
})->with([
    'empty reference' => ['${}', 'malformed_reference'],
    'bad char in reference' => ['${a-b}', 'malformed_reference'],
    'leading digit reference' => ['${1x}', 'malformed_reference'],
    'unterminated reference' => ['${a = 1', 'malformed_reference'],
    'unterminated string' => ["'oops", 'unterminated_string'],
    'lone bang' => ['!', 'unexpected_token'],
    'stray percent' => ['${a} % 1', 'unexpected_token'],
    'blank source' => ['   ', 'empty_expression'],
]);

it('enforces the length budget before scanning', function (): void {
    try {
        makeExpressionLexer()->tokenize(str_repeat('a', 2001));
        $this->fail('expected expression_too_long');
    } catch (ExpressionSyntaxException $e) {
        expect($e->slug())->toBe('expression_too_long');
    }
});

it('enforces the token budget', function (): void {
    try {
        makeExpressionLexer()->tokenize(str_repeat('(', 501)); // 501 chars < length cap, > token cap
        $this->fail('expected expression_too_long');
    } catch (ExpressionSyntaxException $e) {
        expect($e->slug())->toBe('expression_too_long');
    }
});
