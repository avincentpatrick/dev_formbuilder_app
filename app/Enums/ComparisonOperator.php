<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Comparison operators for comparison-style validation rules (data-dictionary §6), mirroring legacy's
 * 6-row `rule_formulas` lookup. Only meaningful for comparison `ValidationRuleType`s.
 */
enum ComparisonOperator: string
{
    case Gt = 'gt';
    case Lt = 'lt';
    case Eq = 'eq';
    case Neq = 'neq';
    case IsNull = 'is_null';
    case Contains = 'contains';
}
