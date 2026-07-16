<?php

declare(strict_types=1);

namespace App\Services\Xlsform\Dto;

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Services\Forms\SchemaSnapshotSerializer;
use App\Services\Xlsform\CascadeResolver;

/**
 * One resolved field for import (Increment G7b). Mirrors the persistent subset of
 * {@see SchemaSnapshotSerializer}'s field shape — the columns the importer writes with
 * an explicit `key`. Mutable by design: the parser builds it during the survey walk, then rewrites it across
 * the cascade-resolution and key-sanitization passes.
 *
 * The three `*` parse-time properties ({@see self::$listName}, {@see self::$choiceFilter}, {@see self::$marker})
 * carry raw XLSForm survey attributes the {@see CascadeResolver} needs to detect and
 * collapse cascades; they are NOT persisted — the importer reads only the fields above them.
 */
final class FieldSpec
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>|null  $labelTranslations
     * @param  array<string, string>|null  $hintTranslations
     * @param  list<ValidationSpec>  $validations
     */
    public function __construct(
        public string $key,
        public FieldType $fieldType,
        public ?string $sectionKey = null,
        public array $config = [],
        public string $label = '',
        public ?array $labelTranslations = null,
        public ?string $hint = null,
        public ?array $hintTranslations = null,
        public ?string $placeholder = null,
        public ?string $defaultValue = null,
        public bool $defaultValueIsExpression = false,
        public RequiredMode $isRequired = RequiredMode::Optional,
        public ?string $relevantExpression = null,
        public ?string $appearance = null,
        public int $sequence = 0,
        public ?int $sectionSequence = null,
        public array $validations = [],
        // ── parse-time only (cascade detection); never persisted ──
        public ?string $listName = null,
        public ?string $choiceFilter = null,
        public ?string $marker = null,
    ) {}
}
