<?php

declare(strict_types=1);

namespace App\Support\Explorer;

use App\Enums\ExplorerFilterOperator;

/** One filter of a data-explorer chart. `values` is empty for the two operators that compare with nothing. */
final readonly class ExplorerFilter
{
    /** @param list<string> $values */
    public function __construct(
        public string $field,
        public ExplorerFilterOperator $operator,
        public array $values = [],
    ) {}

    /** @return array{field: string, operator: string, values: list<string>} */
    public function toArray(): array
    {
        return ['field' => $this->field, 'operator' => $this->operator->value, 'values' => $this->values];
    }
}
