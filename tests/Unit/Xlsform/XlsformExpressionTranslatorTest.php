<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\ValidationRuleType;
use App\Services\Expressions\ExpressionLexer;
use App\Services\Expressions\ExpressionParser;
use App\Services\Expressions\FunctionRegistry;
use App\Services\Xlsform\XlsformExpressionTranslator;

/*
|--------------------------------------------------------------------------
| M244 (`R-bb470c3a`, `D228`): ODK's formula spellings at import. `count-selected` is renamed to `count`; every
| function, operator and word the grammar lacks is named; a whole `regex(., …)` or `string-length(.) op N` becomes
| this product's Pattern or length rule where the question can take it.
|--------------------------------------------------------------------------
*/

it('renames count-selected to count and the result parses', function (): void {
    $translator = new XlsformExpressionTranslator;
    $translated = $translator->translate("count-selected(.) <= 2 and not(selected(., 'noview') and count-selected(.) > 1)");

    expect($translated)->toBe("count(.) <= 2 and not(selected(., 'noview') and count(.) > 1)")
        ->and($translator->unsupported($translated))->toBe([])
        ->and($translator->syntaxProblem($translated))->toBeNull();
});

it('never renames inside a quoted string or a reference', function (): void {
    $translator = new XlsformExpressionTranslator;

    expect($translator->translate("selected(\${a}, 'count-selected(x)') or \"count-selected(.)\" = \${b}"))
        ->toBe("selected(\${a}, 'count-selected(x)') or \"count-selected(.)\" = \${b}");
});

it('names every function, operator and word the grammar does not have, once each', function (): void {
    $unsupported = (new XlsformExpressionTranslator)->unsupported("regex(., '^a') and jr:choice-name(\${a}, '\${a}') or \${x} div 2 AND true() and string-length(.) > 1 and regex(., 'b')");

    expect($unsupported)->toBe(['regex', 'jr:choice-name', 'div', 'AND', 'true', 'string-length'])
        ->and(XlsformExpressionTranslator::isOperatorWord('div'))->toBeTrue()
        ->and(XlsformExpressionTranslator::isOperatorWord('regex'))->toBeFalse();
});

it('reads and and or before a bracket as operators, not calls', function (): void {
    $translator = new XlsformExpressionTranslator;

    expect($translator->unsupported("\${a} = 'x' and (\${b} = 'y' or(\${c} = 'z')) and not(\${d} = '')"))->toBe([]);
});

it('reports a formula the grammar cannot read in the parser\'s words', function (): void {
    expect((new XlsformExpressionTranslator)->syntaxProblem("\${a} == 'x'"))->toContain('Unexpected token');
});

it('turns a whole regex constraint into a Pattern rule', function (string $constraint, string $body): void {
    [$rules, $kept] = (new XlsformExpressionTranslator)->constraint($constraint, FieldType::ShortText);

    expect($kept)->toBeNull()
        ->and($rules)->toHaveCount(1)
        ->and($rules[0]->ruleType)->toBe(ValidationRuleType::Pattern)
        ->and($rules[0]->ruleValue)->toBe($body);
})->with([
    'anchored both ends keeps the inside' => ["regex(., '^\\d{10}\$')", '\\d{10}'],
    'our own export reads back without a new group' => ["regex(., '^(?:[A-Z]{2}|\\d+)\$')", '[A-Z]{2}|\\d+'],
    'unanchored searches' => ["regex(., 'abc')", '[\\s\\S]*(?:abc)[\\s\\S]*'],
    'start only' => ["regex(., '^abc')", '(?:abc)[\\s\\S]*'],
    'double quotes' => ['regex(., "^[a-z\']+$")', "[a-z']+"],
]);

it('turns string-length bounds into length rules, either way round', function (string $constraint, ?int $min, ?int $max): void {
    [$rules, $kept] = (new XlsformExpressionTranslator)->constraint($constraint, FieldType::LongText);

    $byType = [];
    foreach ($rules as $rule) {
        $byType[$rule->ruleType?->value] = $rule->ruleValue;
    }

    expect($kept)->toBeNull()
        ->and($byType['min_length'] ?? null)->toBe($min === null ? null : (string) $min)
        ->and($byType['max_length'] ?? null)->toBe($max === null ? null : (string) $max);
})->with([
    'at most' => ['string-length(.) <= 1500', null, 1500],
    'below' => ['string-length(.) < 11', null, 10],
    'at least' => ['string-length(.) >= 3', 3, null],
    'above' => ['string-length(.) > 2', 3, null],
    'exactly' => ['string-length(.) = 10', 10, 10],
    'flipped' => ['10 >= string-length(.)', null, 10],
]);

it('splits a constraint on its top-level and, and keeps the rest in the author\'s words', function (): void {
    [$rules, $kept] = (new XlsformExpressionTranslator)->constraint("regex(., '^[0-9]+\$') and (. > 5 or . = 0) and string-length(.) <= 4", FieldType::ShortText);

    expect(array_map(static fn ($rule) => $rule->ruleType?->value, $rules))->toBe(['pattern', 'max_length'])
        ->and($kept)->toBe('(. > 5 or . = 0)');
});

it('does not split a constraint with a top-level or, because and binds tighter', function (): void {
    [$rules, $kept] = (new XlsformExpressionTranslator)->constraint("regex(., 'a') and . != 'x' or . = 'y'", FieldType::ShortText);

    expect($rules)->toBe([])->and($kept)->toBe("regex(., 'a') and . != 'x' or . = 'y'");
});

it('keeps a regex as written where the question cannot take a Pattern rule, or the pattern is PCRE-only', function (string $constraint, FieldType $type): void {
    [$rules, $kept] = (new XlsformExpressionTranslator)->constraint($constraint, $type);

    expect($rules)->toBe([])->and($kept)->toBe($constraint);
})->with([
    'an integer' => ["regex(., '^[0-9]+\$')", FieldType::Integer],
    'a choice' => ["regex(., 'a')", FieldType::SingleSelect],
    'an inline flag' => ["regex(., '(?i)^abc\$')", FieldType::ShortText],
    'a possessive quantifier' => ["regex(., '^a++\$')", FieldType::ShortText],
    'a pattern that does not compile' => ["regex(., '^(a\$')", FieldType::ShortText],
    'string-length on a number' => ['string-length(.) <= 3', FieldType::Integer],
    'string-length with a fraction' => ['string-length(.) <= 3.5', FieldType::ShortText],
]);

it('names an ODK hyphenated call the parser could only read as a subtraction', function (): void {
    $parser = new ExpressionParser(new ExpressionLexer, new FunctionRegistry);

    expect($parser->hyphenatedCall('string-length(.) > 3'))->toBe('string-length')
        ->and($parser->hyphenatedCall('count-selected(.) <= 2'))->toBe('count-selected')
        ->and($parser->hyphenatedCall('${a} - ${b} > 3'))->toBeNull()
        ->and($parser->hyphenatedCall('count(.) - 1 > 3'))->toBeNull();
});
