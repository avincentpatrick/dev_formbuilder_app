<?php

declare(strict_types=1);

namespace App\Support\Mapping;

/**
 * One column of a {@see ColumnMapping}: the normalized header label, and the form-field key bound to it — or
 * null, meaning the tenant keeps this column for their own use and we neither write nor read it.
 *
 * The header is stored NORMALIZED rather than raw. A mapping's job is to survive the cosmetic edits a human
 * makes to a header row, so keeping the raw label would invite a later reader to compare against it and
 * quietly reintroduce the case/whitespace sensitivity {@see ColumnFingerprint} exists to remove. The raw label
 * belongs on screen (H16b renders the artifact's own header row), not in the binding.
 */
final readonly class MappedColumn
{
    public function __construct(
        public string $header,
        public ?string $fieldKey,
    ) {}

    public function isBound(): bool
    {
        return $this->fieldKey !== null;
    }
}
