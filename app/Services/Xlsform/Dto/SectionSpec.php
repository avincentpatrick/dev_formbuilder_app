<?php

declare(strict_types=1);

namespace App\Services\Xlsform\Dto;

use App\Services\Forms\SchemaSnapshotSerializer;

/**
 * One resolved section (XLSForm `begin group`/`begin repeat`) for import (Increment G7b). Mirrors the
 * relevant subset of {@see SchemaSnapshotSerializer}'s section shape, with the stable
 * `key` carried straight from the XLSForm `name`. Mutable by design — the parser rewrites `key` during the
 * final key-sanitization pass (§ key rules), so this is a working record, not an immutable value object.
 */
final class SectionSpec
{
    /**
     * @param  array<string, string>|null  $labelTranslations
     * @param  array<string, string>|null  $descriptionTranslations
     */
    public function __construct(
        public string $key,
        public string $label = '',
        public ?array $labelTranslations = null,
        public ?string $description = null,
        public ?array $descriptionTranslations = null,
        public int $sequence = 0,
        public bool $isRepeatable = false,
        public ?int $minInstances = null,
        public ?int $maxInstances = null,
        public ?string $relevantExpression = null,
    ) {}
}
