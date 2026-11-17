<?php

declare(strict_types=1);

namespace App\Services\Xlsform;

use App\Enums\ComparisonOperator;
use App\Enums\FieldType;
use App\Enums\LogicOperator;
use App\Enums\ValidationRuleType;
use App\Enums\ValueShape;
use App\Exceptions\Expressions\ExpressionSyntaxException;
use App\Services\Expressions\Coercion;
use App\Services\Expressions\ExpressionLexer;
use App\Services\Expressions\Token;
use App\Services\Expressions\TokenType;
use App\Services\Xlsform\Dto\FieldSpec;
use App\Services\Xlsform\Dto\ValidationSpec;

/**
 * "Required when…" in and out of XLSForm's `required` column (M244, `R-ea0cc482`, `R-0ceefbb4`).
 *
 * ⛔ ODK'S `required` TAKES A FORMULA, AND BOTH DIRECTIONS LOST IT. The import read the column as yes or blank, so
 * `${cut} = 'change'` came in as optional with no warning, and the stand-in an author reaches for next, a
 * constraint, never runs on a blank answer. The export wrote a conditionally required question as `yes`, so a round
 * trip made it always required. `docs/xlsform-interop-spec.md` §2 justified both by saying XLSForm has no such
 * column; it has.
 *
 * {@see self::read()} takes a formula of simple checks joined by `and`, `or` and parentheses, and turns it into this
 * product's own rule units: each either-of branch one rule, each all-of branch one `logic_group` (the shape
 * `SemanticValidator::toUnits()` reads). A check compares one question with a value either way round, or is
 * `selected(${k}, 'v')`, `contains(${k}, 'v')`, `${k} = ''` or `${k} != ''`, optionally inside `not(…)`. Each check is
 * held to what the compared question's shape takes ({@see ValueShape::allowsOperator()}), so a form it imports is one
 * publish accepts. Anything else is unreadable, and the caller imports the question as optional with a warning.
 *
 * {@see self::render()} is the inverse, for the export, and writes only what {@see self::read()} reads back.
 */
final class XlsformRequiredFormula
{
    /** At most this many either-of branches … */
    public const MAX_UNITS = 10;

    /** … and this many checks in all. */
    public const MAX_RULES = 40;

    private const ORDERED = ['gt', 'lt', 'gte', 'lte'];

    /** @var list<Token> */
    private array $tokens = [];

    private int $at = 0;

    public function __construct(private readonly ExpressionLexer $lexer = new ExpressionLexer) {}

    /**
     * The rule rows a `required` formula stands for, or null when it cannot be read as "Required when…".
     *
     * @param  array<string, FieldSpec>  $fieldsByKey  every question by its key as the workbook writes it
     * @return non-empty-list<ValidationSpec>|null
     */
    public function read(string $formula, FieldSpec $owner, array $fieldsByKey): ?array
    {
        if (! ValueShape::for($owner->fieldType)->allows(ValidationRuleType::RequiredIf)) {
            return null;
        }

        try {
            $this->tokens = $this->lexer->tokenize($formula);
        } catch (ExpressionSyntaxException) {
            return null;
        }
        $this->at = 0;

        $units = $this->orExpression();
        if ($units === null || $this->peek()->type !== TokenType::Eof) {
            return null;
        }

        $checkCount = array_sum(array_map('count', $units));
        if ($units === [] || count($units) > self::MAX_UNITS || $checkCount > self::MAX_RULES) {
            return null;
        }

        $rows = [];
        $group = 0;
        foreach ($units as $unit) {
            $grouped = count($unit) > 1;
            foreach ($unit as $check) {
                $row = $this->resolve($check, $owner, $fieldsByKey, $grouped ? $group : null);
                if ($row === null) {
                    return null;
                }
                $rows[] = $row->at(count($rows));
            }
            if ($grouped) {
                $group++;
            }
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * The `required` formula for a question's "Required when…" rows (the snapshot's shape), or null when one of them
     * cannot be written as a check {@see self::read()} reads back.
     *
     * @param  list<array<string, mixed>>  $validations  the field's snapshot validations
     * @param  array<string, FieldType>  $typesByKey  every question's type by key
     */
    public function render(array $validations, array $typesByKey): ?string
    {
        /** @var list<list<array<string, mixed>>> $units */
        $units = [];
        /** @var array<int, int> $unitByGroup group ordinal → index in $units */
        $unitByGroup = [];

        foreach ($validations as $row) {
            $type = ValidationRuleType::tryFrom((string) ($row['rule_type'] ?? ''));
            if ($type === null || ! $type->governsRequiredness()) {
                continue;
            }
            $ordinal = $row['logic_group_ordinal'] ?? null;
            if (is_int($ordinal)) {
                if (! isset($unitByGroup[$ordinal])) {
                    $unitByGroup[$ordinal] = count($units);
                    $units[] = [];
                }
                $units[$unitByGroup[$ordinal]][] = $row;
            } else {
                $units[] = [$row];
            }
        }

        if ($units === []) {
            return null;
        }

        $branches = [];
        foreach ($units as $unit) {
            $text = $this->renderUnit($unit, $typesByKey);
            if ($text === null) {
                return null;
            }
            $branches[] = count($units) > 1 && count($unit) > 1 ? '('.$text.')' : $text;
        }

        return implode(' or ', $branches);
    }

    // ── Reading ──────────────────────────────────────────────────────────────────────────────────

    /**
     * `a or b or …` as either-of branches, each a list of all-of checks.
     *
     * @return list<list<array{key: string, op: string, value: ?string}>>|null
     */
    private function orExpression(): ?array
    {
        $units = $this->andExpression();
        if ($units === null) {
            return null;
        }

        while ($this->isWord('or')) {
            $this->at++;
            $next = $this->andExpression();
            if ($next === null) {
                return null;
            }
            array_push($units, ...$next);
        }

        return $units;
    }

    /**
     * `a and b and …`: the cross product of the operands' branches.
     *
     * @return list<list<array{key: string, op: string, value: ?string}>>|null
     */
    private function andExpression(): ?array
    {
        $units = $this->atom();
        if ($units === null) {
            return null;
        }

        while ($this->isWord('and')) {
            $this->at++;
            $right = $this->atom();
            if ($right === null) {
                return null;
            }
            $product = [];
            foreach ($units as $left) {
                foreach ($right as $branch) {
                    $product[] = [...$left, ...$branch];
                }
            }
            if (count($product) > self::MAX_UNITS) {
                return null;
            }
            $units = $product;
        }

        return $units;
    }

    /**
     * @return list<list<array{key: string, op: string, value: ?string}>>|null
     */
    private function atom(): ?array
    {
        if ($this->peek()->type === TokenType::LParen) {
            $this->at++;
            $inner = $this->orExpression();
            if ($inner === null || ! $this->take(TokenType::RParen)) {
                return null;
            }

            return $inner;
        }

        if ($this->isWord('not') && $this->peek(1)->type === TokenType::LParen) {
            $this->at += 2;
            $check = $this->check();
            if ($check === null || ! $this->take(TokenType::RParen)) {
                return null;
            }
            $negated = $this->negate($check);

            return $negated === null ? null : [[$negated]];
        }

        $check = $this->check();

        return $check === null ? null : [[$check]];
    }

    /**
     * One check: `${k} op v`, `v op ${k}`, `selected(${k}, v)` or `contains(${k}, v)`.
     *
     * @return array{key: string, op: string, value: ?string}|null
     */
    private function check(): ?array
    {
        $token = $this->peek();

        if ($token->type === TokenType::Word && ($token->lexeme === 'selected' || $token->lexeme === 'contains')) {
            $this->at++;
            if (! $this->take(TokenType::LParen)) {
                return null;
            }
            $ref = $this->peek();
            if ($ref->type !== TokenType::FieldRef) {
                return null;
            }
            $this->at++;
            if (! $this->take(TokenType::Comma)) {
                return null;
            }
            $literal = $this->literal();
            if ($literal === null || ! $this->take(TokenType::RParen)) {
                return null;
            }

            return ['key' => $ref->lexeme, 'op' => $token->lexeme === 'selected' ? 'selected' : 'contains', 'value' => $literal];
        }

        if ($token->type === TokenType::FieldRef) {
            $this->at++;
            $op = $this->operator();
            $literal = $op === null ? null : $this->literal();
            if ($op === null || $literal === null) {
                return null;
            }

            return $this->comparison($token->lexeme, $op, $literal);
        }

        $literal = $this->literal();
        if ($literal !== null) {
            $op = $this->operator();
            $ref = $this->peek();
            if ($op === null || $ref->type !== TokenType::FieldRef) {
                return null;
            }
            $this->at++;

            return $this->comparison($ref->lexeme, $this->flip($op), $literal);
        }

        return null;
    }

    /**
     * @return array{key: string, op: string, value: ?string}|null
     */
    private function comparison(string $key, string $op, string $literal): ?array
    {
        if ($literal === '') {
            return match ($op) {
                'eq' => ['key' => $key, 'op' => 'is_null', 'value' => null],
                'neq' => ['key' => $key, 'op' => 'answered', 'value' => null],
                default => null,
            };
        }

        return ['key' => $key, 'op' => $op, 'value' => $literal];
    }

    /**
     * @param  array{key: string, op: string, value: ?string}  $check
     * @return array{key: string, op: string, value: ?string}|null
     */
    private function negate(array $check): ?array
    {
        $op = match ($check['op']) {
            'eq' => 'neq',
            'neq' => 'eq',
            'gt' => 'lte',
            'lte' => 'gt',
            'lt' => 'gte',
            'gte' => 'lt',
            'is_null' => 'answered',
            'answered' => 'is_null',
            'selected' => 'not_selected',
            default => null,
        };

        return $op === null ? null : ['key' => $check['key'], 'op' => $op, 'value' => $check['value']];
    }

    /**
     * One check as a rule row against the question it names, or null when that question cannot take it.
     *
     * @param  array{key: string, op: string, value: ?string}  $check
     * @param  array<string, FieldSpec>  $fieldsByKey
     */
    private function resolve(array $check, FieldSpec $owner, array $fieldsByKey, ?int $group): ?ValidationSpec
    {
        $related = $fieldsByKey[$check['key']] ?? null;
        if ($related === null || $related === $owner) {
            return null;
        }
        $shape = ValueShape::for($related->fieldType);

        $operator = match ($check['op']) {
            'selected' => $shape === ValueShape::MultipleChoice || $shape === ValueShape::Hierarchy
                ? ComparisonOperator::Contains
                : ($shape === ValueShape::Choice || $shape === ValueShape::Boolean || $shape === ValueShape::Scale ? ComparisonOperator::Eq : null),
            'not_selected' => $shape === ValueShape::Choice || $shape === ValueShape::Boolean || $shape === ValueShape::Scale ? ComparisonOperator::Neq : null,
            'answered' => null,
            default => ComparisonOperator::tryFrom($check['op']),
        };

        if ($check['op'] === 'answered') {
            if (! $shape->allowsOperator(ComparisonOperator::IsNull)) {
                return null;
            }

            return new ValidationSpec(
                ruleType: ValidationRuleType::RequiredWith,
                relatedField: $related,
                group: $group,
                logicOperator: $group !== null ? LogicOperator::And : null,
            );
        }

        if ($operator === null || ! $shape->allowsOperator($operator)) {
            return null;
        }

        return new ValidationSpec(
            ruleType: ValidationRuleType::RequiredIf,
            operator: $operator,
            ruleValue: $operator === ComparisonOperator::IsNull ? null : $check['value'],
            relatedField: $related,
            group: $group,
            logicOperator: $group !== null ? LogicOperator::And : null,
        );
    }

    private function operator(): ?string
    {
        $op = match ($this->peek()->type) {
            TokenType::Eq => 'eq',
            TokenType::Neq => 'neq',
            TokenType::Gt => 'gt',
            TokenType::Lt => 'lt',
            TokenType::Gte => 'gte',
            TokenType::Lte => 'lte',
            default => null,
        };
        if ($op !== null) {
            $this->at++;
        }

        return $op;
    }

    private function flip(string $op): string
    {
        return match ($op) {
            'gt' => 'lt',
            'lt' => 'gt',
            'gte' => 'lte',
            'lte' => 'gte',
            default => $op,
        };
    }

    /** A quoted string, or a number with an optional leading minus. */
    private function literal(): ?string
    {
        $token = $this->peek();
        if ($token->type === TokenType::String || $token->type === TokenType::Number) {
            $this->at++;

            return $token->lexeme;
        }
        if ($token->type === TokenType::Minus && $this->peek(1)->type === TokenType::Number) {
            $this->at += 2;

            return '-'.$this->tokens[$this->at - 1]->lexeme;
        }

        return null;
    }

    private function take(TokenType $type): bool
    {
        if ($this->peek()->type !== $type) {
            return false;
        }
        $this->at++;

        return true;
    }

    private function isWord(string $word): bool
    {
        $token = $this->peek();

        return $token->type === TokenType::Word && $token->lexeme === $word;
    }

    private function peek(int $ahead = 0): Token
    {
        return $this->tokens[$this->at + $ahead] ?? $this->tokens[count($this->tokens) - 1];
    }

    // ── Writing ──────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $unit
     * @param  array<string, FieldType>  $typesByKey
     */
    private function renderUnit(array $unit, array $typesByKey): ?string
    {
        // A group folds left to right in sequence order (`StructuredRuleLowering::lowerConditionGroup()`), so the text
        // so far is wrapped whenever the joining word changes, and the formula reads back to the same fold.
        $text = null;
        $previous = null;
        foreach ($unit as $row) {
            $check = $this->renderCheck($row, $typesByKey);
            if ($check === null) {
                return null;
            }
            if ($text === null) {
                $text = $check;

                continue;
            }
            $join = ($row['logic_operator'] ?? null) === 'or' ? 'or' : 'and';
            $text = ($previous !== null && $previous !== $join ? '('.$text.')' : $text).' '.$join.' '.$check;
            $previous = $join;
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, FieldType>  $typesByKey
     */
    private function renderCheck(array $row, array $typesByKey): ?string
    {
        $key = $row['related_field_key'] ?? null;
        if (! is_string($key) || $key === '' || ! isset($typesByKey[$key])) {
            return null;
        }
        $ref = '${'.$key.'}';
        $operator = $row['operator'] ?? null;
        $value = $row['rule_value'] ?? null;
        $value = $value === null ? null : (string) $value;

        if (($row['rule_type'] ?? null) === ValidationRuleType::RequiredWith->value && $operator === null) {
            return $ref." != ''";
        }

        if ($operator === ComparisonOperator::IsNull->value) {
            return $ref." = ''";
        }

        if ($value === null || $value === '') {
            return null;
        }

        if ($operator === ComparisonOperator::Contains->value) {
            $shape = ValueShape::for($typesByKey[$key]);
            $literal = $this->quote($value);
            if ($literal === null) {
                return null;
            }
            $function = $shape === ValueShape::MultipleChoice || $shape === ValueShape::Hierarchy ? 'selected' : 'contains';

            return $function.'('.$ref.', '.$literal.')';
        }

        $symbol = match ($operator) {
            'eq' => '=',
            'neq' => '!=',
            'gt' => '>',
            'lt' => '<',
            'gte' => '>=',
            'lte' => '<=',
            default => null,
        };
        if ($symbol === null) {
            return null;
        }

        $literal = in_array($operator, self::ORDERED, true) && Coercion::isNumericLike($value) && preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $value) === 1
            ? $value
            : $this->quote($value);

        return $literal === null ? null : $ref.' '.$symbol.' '.$literal;
    }

    /** A literal the lexer reads back exactly: it has no escapes, so a value holding both quote marks cannot be written. */
    private function quote(string $value): ?string
    {
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }
        if (! str_contains($value, '"')) {
            return '"'.$value.'"';
        }

        return null;
    }
}
