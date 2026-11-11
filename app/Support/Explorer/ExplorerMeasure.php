<?php

declare(strict_types=1);

namespace App\Support\Explorer;

use App\Enums\ExplorerAggregate;

/** One value of a data-explorer chart: what to compute, and over which field (none, for a row count). */
final readonly class ExplorerMeasure
{
    public function __construct(
        public ExplorerAggregate $aggregate,
        public ?string $field = null,
    ) {}

    /** @return array{aggregate: string, field: string|null} */
    public function toArray(): array
    {
        return ['aggregate' => $this->aggregate->value, 'field' => $this->field];
    }
}
