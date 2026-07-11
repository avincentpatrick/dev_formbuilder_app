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
 * pre-G1 golden vectors are unaffected.
 *
 * Sub-field addressing (Increment G4): a failure inside a composite field's value — a cascading level
 * (`cellPath = "<levelIndex>"`), and in G4b a matrix cell (`"row.col"`) or Likert-matrix row (`"row"`) —
 * carries an optional `cellPath` appended to the base address as `.cellPath`. It composes with every prior
 * axis and defaults null, so every existing scalar/repeat error renders exactly as before.
 */
final readonly class SemanticError
{
    public function __construct(
        public string $fieldKey,
        public string $rule,
        public string $message,
        public ?string $sectionKey = null,
        public ?int $instanceIndex = null,
        public ?string $cellPath = null,
    ) {}

    /**
     * The stable address the surface + the 422 envelope key on: `field`, `section`, `section[i].field`, or
     * any of those suffixed with `.cellPath` for a sub-field (composite) failure.
     */
    public function path(): string
    {
        $base = $this->baseAddress();

        return $this->cellPath === null ? $base : "{$base}.{$this->cellPath}";
    }

    private function baseAddress(): string
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
