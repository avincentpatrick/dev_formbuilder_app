<?php

declare(strict_types=1);

namespace App\Services\Ocr\Bakeoff;

use App\Enums\FieldType;

/**
 * One question the reader answers, as the bake-off harness needs it (M136): its key and printed label (to match an
 * answer-sheet column), its type and config (to turn a typed answer into the stored shape), and its printed options
 * (a choice is typed as the option's label or value).
 */
final readonly class OcrBakeoffQuestion
{
    /**
     * @param  array<string, mixed>  $config
     * @param  list<array{value: string, label: string}>  $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?FieldType $type,
        public array $config,
        public array $options,
    ) {}
}
