<?php

declare(strict_types=1);

namespace App\Support\Explorer;

use App\Enums\ExplorerBucket;

/** One group-by of a data-explorer chart: a field, and for a date the period to group it into. */
final readonly class ExplorerDimension
{
    public function __construct(
        public string $field,
        public ?ExplorerBucket $bucket = null,
    ) {}

    /** @return array{field: string, bucket: string|null} */
    public function toArray(): array
    {
        return ['field' => $this->field, 'bucket' => $this->bucket?->value];
    }
}
