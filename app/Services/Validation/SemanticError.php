<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Enums\ValidationRuleType;

/**
 * One per-field Stage-3 semantic failure (technical-architecture.md §4.1 §4.3). `rule` is a STABLE
 * identifier the surface can branch on without parsing the message — a {@see ValidationRuleType}
 * value (`min_value`, `pattern`, …), `'constraint'` for a free-text expression, `'field_required'` for a
 * failed (possibly conditional) required check, or `'min_instances'`/`'max_instances'` for a repeat-group
 * instance-count failure. `message` is already resolved + localized.
 *
 * Repeat-group addressing (Increment G1): a failure inside a repeatable section carries the owning
 * `sectionKey` + the 0-based `instanceIndex`, and `path()` renders `section[i].field`. A section-level
 * count failure carries the `sectionKey` with a null `instanceIndex`, and `path()` renders just `section`.
 * A flat (non-repeat) failure leaves both null, and `path() === fieldKey` — so pre-repeat callers and the
 * 38 pre-G1 golden vectors are unaffected.
 */
final readonly class SemanticError
{
    public function __construct(
        public string $fieldKey,
        public string $rule,
        public string $message,
        public ?string $sectionKey = null,
        public ?int $instanceIndex = null,
    ) {}

    /** The stable address the surface + the 422 envelope key on: `field`, `section`, or `section[i].field`. */
    public function path(): string
    {
        if ($this->sectionKey === null) {
            return $this->fieldKey;
        }

        if ($this->instanceIndex === null) {
            return $this->sectionKey;
        }

        return "{$this->sectionKey}[{$this->instanceIndex}].{$this->fieldKey}";
    }
}
