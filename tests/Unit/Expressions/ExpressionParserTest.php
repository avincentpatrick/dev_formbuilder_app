<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\ExpressionKind;
use App\Enums\LogicOperator;
use App\Exceptions\Expressions\ExpressionSyntaxException;
use App\Services\Expressions\Ast\ComparisonNode;
use App\Services\Expressions\Ast\FieldReferenceNode;
use App\Services\Expressions\Ast\LogicalNode;

it('binds "and" tighter than "or"', function (): void {
    $ast = makeExpressionParser()->parse('${a} = 1 or ${b} = 2 and ${c} = 3');

    expect($ast)->toBeInstanceOf(LogicalNode::class);
    /** @var LogicalNode $ast */
    expect($ast->op)->toBe(LogicOperator::Or);
    expect($ast->left)->toBeInstanceOf(ComparisonNode::class);   // ${a} = 1
    expect($ast->right)->toBeInstanceOf(LogicalNode::class);     // (${b} = 2 and ${c} = 3)
    /** @var LogicalNode $right */
    $right = $ast->right;
    expect($right->op)->toBe(LogicOperator::And);
});

it('folds a leading minus into a negative numeric literal', function (): void {
    $ast = makeExpressionParser()->parse('${t} > -5');

    expect($ast)->toBeInstanceOf(ComparisonNode::class);
    /** @var ComparisonNode $ast */
    expect($ast->op)->toBe(ComparisonOperator::Gt);
    expect($ast->left)->toBeInstanceOf(FieldReferenceNode::class);
});

it('rejects each malformed expression with the right slug', function (string $source, string $slug): void {
    try {
        makeExpressionParser()->parse($source);
        $this->fail("expected {$slug} for: {$source}");
    } catch (ExpressionSyntaxException $e) {
        expect($e->slug())->toBe($slug);
    }
})->with([
    'chained comparison' => ['1 < 2 < 3', 'unexpected_token'],
    'chained gte' => ['1 <= 2 <= 3', 'unexpected_token'],
    'double equals' => ['${a} == 1', 'unexpected_token'],
    'trailing plus' => ['${a} +', 'unexpected_token'],
    'unbalanced paren' => ['(${a} = 1', 'unexpected_token'],
    'bare word operand' => ['${a} = bar', 'unexpected_token'],
    'not without parens' => ['not ${a}', 'unexpected_token'],
    'unknown function sum' => ['sum(1, 2)', 'unknown_function'],
    'unknown function contains' => ['contains(${a}, \'x\')', 'unknown_function'],
    'selected too few' => ['selected(${m})', 'arity_mismatch'],
    'selected too many' => ['selected(${m}, \'a\', \'b\')', 'arity_mismatch'],
    'if too few' => ['if(${a}, 1)', 'arity_mismatch'],
    'count too many' => ['count(${a}, ${b})', 'arity_mismatch'],
    'count boolean arg' => ['count(${a} = 1)', 'non_value_operand'],
    'arithmetic boolean operand' => ['1 + (${a} = 1)', 'non_value_operand'],
    'boolean operand' => ['selected(${m}, \'a\') = 1', 'non_value_operand'],
    'boolean paren operand' => ['(${a} = 1) = 1', 'non_value_operand'],
    'boolean selected arg' => ['selected(selected(${a}, \'x\'), \'y\')', 'non_value_operand'],
]);

it('rejects an expression nested past the depth cap', function (): void {
    $deep = str_repeat('not(', 65).'${a} = 1'.str_repeat(')', 65);

    try {
        makeExpressionParser()->parse($deep);
        $this->fail('expected too_deeply_nested');
    } catch (ExpressionSyntaxException $e) {
        expect($e->slug())->toBe('too_deeply_nested');
    }
});

it('memoizes: the same source returns the identical Node instance', function (): void {
    $parser = makeExpressionParser();

    expect($parser->parse('${a} = 1'))->toBe($parser->parse('${a} = 1'));
});

it('validates references at publish time', function (): void {
    $parser = makeExpressionParser();
    $ast = $parser->parse('${age} > 15');

    // A known key passes.
    $parser->assertReferencesResolve($ast, ['age' => true], ExpressionKind::Relevant);

    // An unknown key throws.
    try {
        $parser->assertReferencesResolve($ast, [], ExpressionKind::Relevant, 'age');
        $this->fail('expected unknown_field_reference');
    } catch (ExpressionSyntaxException $e) {
        expect($e->slug())->toBe('unknown_field_reference');
        expect($e->fieldKey())->toBe('age');
    }
});

it('allows "." only in a constraint', function (): void {
    $parser = makeExpressionParser();
    $ast = $parser->parse(". = ''");

    // Fine as a constraint.
    $parser->assertReferencesResolve($ast, [], ExpressionKind::Constraint);

    // Illegal as relevance.
    try {
        $parser->assertReferencesResolve($ast, [], ExpressionKind::Relevant, 'weight');
        $this->fail('expected self_reference_in_non_constraint');
    } catch (ExpressionSyntaxException $e) {
        expect($e->slug())->toBe('self_reference_in_non_constraint');
    }
});
