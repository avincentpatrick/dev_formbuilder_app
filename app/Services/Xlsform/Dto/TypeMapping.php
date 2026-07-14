<?php

declare(strict_types=1);

namespace App\Services\Xlsform\Dto;

/**
 * One forward (FieldType → XLSForm) mapping entry (Increment G7 / docs/xlsform-interop-spec.md §3).
 *
 * `type` is the XLSForm `survey.type` token (for selects the exporter appends the `list_name`), `appearance`
 * is a forced XLSForm appearance (e.g. `multiline`, `minimal`, `signature`), `marker` is a breadcrumb written
 * to the ignored `#meridian` survey column so this product's OWN re-import can recover a type XLSForm cannot
 * natively carry, and `exportOnly` flags the documented lossy/one-directional cases (§3): `duration`,
 * `likert_matrix`, `matrix`, `page_break`.
 */
final readonly class TypeMapping
{
    public function __construct(
        public string $type,
        public ?string $appearance = null,
        public ?string $marker = null,
        public bool $exportOnly = false,
    ) {}
}
