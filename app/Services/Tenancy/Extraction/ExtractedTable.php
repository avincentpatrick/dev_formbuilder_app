<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Extraction;

use App\Enums\ExtractFilter;
use App\Enums\TenantTableClass;
use App\Support\Tenancy\TenantExtractColumns;

/**
 * What the extractor did to one table, as reported in the manifest (Phase 4, P2b).
 *
 * The withheld map is carried per table rather than referenced by a pointer to
 * {@see TenantExtractColumns} because the artefact outlives this codebase. Somebody
 * reading an extract two years from now, on a machine that has never had this repository on it, must be
 * able to answer "what is NOT in this file and why" from the file itself.
 *
 * ⚠️ THERE IS DELIBERATELY NO `TenantTableClass` FIELD HERE, and the omission is a correction rather than
 * a simplification. Reporting one would mean calling {@see TenantTableClass} on `users`, `domains` and
 * `tenants` — all three of which classify as `not_extracted` and are all three extracted, for reasons that
 * enum was never asked about. A manifest line reading `class: not_extracted, rows: 4` is not a shorthand,
 * it is a false statement. {@see ExtractFilter} answers the question a reader actually has (what
 * restricted these rows to this tenant, and was it the database or a `where` clause) and answers it
 * correctly for all four shapes.
 */
final readonly class ExtractedTable
{
    /**
     * @param  list<string>  $columns  what the NDJSON rows actually contain, in order
     * @param  array<string, string>  $withheld  column => the reason it was not read
     * @param  int|null  $platformRowsExcluded  non-null only where RLS returns a superset
     */
    public function __construct(
        public string $name,
        public ExtractFilter $filter,
        public int $rows,
        public array $columns,
        public array $withheld,
        public ?int $platformRowsExcluded = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $report = [
            'table' => $this->name,
            'filter' => $this->filter->value,
            'filter_is_database_enforced' => $this->filter->isDatabaseEnforced(),
            'rows' => $this->rows,
            'columns' => $this->columns,
        ];

        if ($this->withheld !== []) {
            $report['withheld_columns'] = $this->withheld;
        }

        if ($this->platformRowsExcluded !== null) {
            $report['platform_rows_excluded'] = $this->platformRowsExcluded;
        }

        return $report;
    }
}
