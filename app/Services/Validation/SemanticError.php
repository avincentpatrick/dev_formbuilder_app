<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Enums\ValidationRuleType;

/**
 * One per-field Stage-3 semantic failure (technical-architecture.md §4.1 §4.3). `rule` is a STABLE
 * identifier the surface can branch on without parsing the message — a {@see ValidationRuleType}
 * value (`min_value`, `pattern`, …), `'constraint'` for a free-text expression, or `'field_required'` for a
 * failed (possibly conditional) required check. `message` is already resolved + localized.
 */
final readonly class SemanticError
{
    public function __construct(
        public string $fieldKey,
        public string $rule,
        public string $message,
    ) {}
}
