<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Structured validation rule kinds (data-dictionary §6), mirroring legacy's 11-row `rule_types`
 * lookup. Mutually exclusive with a free-text `expression` on the same row (a DB CHECK enforces
 * exactly one of `rule_type` / `expression`).
 */
enum ValidationRuleType: string
{
    case MinValue = 'min_value';
    case MaxValue = 'max_value';
    case MinLength = 'min_length';
    case MaxLength = 'max_length';
    case Pattern = 'pattern';
    case RequiredIf = 'required_if';
    case RequiredWith = 'required_with';
    case SkipIf = 'skip_if';
    case SkipWith = 'skip_with';
    case GreaterThanField = 'greater_than_field';
    case LessThanField = 'less_than_field';
}
