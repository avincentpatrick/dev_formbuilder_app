<?php

declare(strict_types=1);

namespace App\Services\Forms\Dto;

use App\Services\Forms\SchemaBlueprintMaterializer;

/**
 * The outcome of materializing a schema blueprint into a draft version — the row counts written (Increment
 * G9a). Returned by {@see SchemaBlueprintMaterializer::materializeInto()}, mirroring the Xlsform import result.
 */
final class MaterializeResult
{
    public function __construct(
        public readonly int $sectionCount,
        public readonly int $fieldCount,
        public readonly int $validationCount,
    ) {}
}
