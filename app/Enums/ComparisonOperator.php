<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Comparison operators for comparison-style validation rules (data-dictionary §6), mirroring legacy's
 * 6-row `rule_formulas` lookup, plus the `>=`/`<=` operators added with grammar v2.0 (Increment G3). Only
 * meaningful for comparison `ValidationRuleType`s and the expression grammar's comparison nodes.
 */
enum ComparisonOperator: string
{
    case Gt = 'gt';
    case Lt = 'lt';
    case Gte = 'gte';
    case Lte = 'lte';
    case Eq = 'eq';
    case Neq = 'neq';
    case IsNull = 'is_null';
    case Contains = 'contains';
}
