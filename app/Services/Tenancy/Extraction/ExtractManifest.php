<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Extraction;

use App\Support\Tenancy\TenantExtractColumns;

/**
 * The artefact's own description of itself (Phase 4, P2b — ADR-0018 §D6).
 *
 * ⚠️ THE MANIFEST IS THE PART OF AN EXTRACT THAT CAN BE WRONG WITHOUT ANYTHING FAILING, so everything in
 * it is MEASURED during the run rather than asserted from the code that configured it. `snapshot.role` and
 * `snapshot.isolation_level` are read back out of the session; `rows` is counted as lines are written, not
 * predicted by a `count(*)`; `platform_rows_excluded` is a second query rather than an arithmetic
 * difference. An extract whose manifest was computed from intent would agree with itself in exactly the
 * cases where it was wrong.
 *
 * The most important field is the one that is usually absent: `unresolved_user_references`. See
 * {@see self::$unresolvedUserReferences}.
 */
final readonly class ExtractManifest
{
    public const int FORMAT_VERSION = 1;

    /**
     * @param  array{id: string, slug: string|null, name: string|null}  $tenant
     * @param  array{database: string, role: string, isolation_level: string, application: string}  $snapshot
     * @param  list<ExtractedTable>  $tables
     * @param  array<string, list<string>>  $unresolvedUserReferences  "table.column" => the ids it points at
     *                                                                 that the extract does not contain
     */
    public function __construct(
        public array $tenant,
        public string $generatedAt,
        public array $snapshot,
        public array $tables,
        public array $unresolvedUserReferences,
    ) {}

    public function rowTotal(): int
    {
        return array_sum(array_map(static fn (ExtractedTable $t): int => $t->rows, $this->tables));
    }

    public function unresolvedReferenceTotal(): int
    {
        return count(array_unique(array_merge(...array_values($this->unresolvedUserReferences)) ?: []));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format_version' => self::FORMAT_VERSION,
            'generated_at' => $this->generatedAt,
            'generated_by' => 'php artisan tenants:extract',
            'tenant' => $this->tenant,
            'snapshot' => $this->snapshot,
            'row_total' => $this->rowTotal(),
            'tables' => array_map(static fn (ExtractedTable $t): array => $t->toArray(), $this->tables),
            'unresolved_user_references' => [
                'distinct_users' => $this->unresolvedReferenceTotal(),
                // ⚠️ NOT AN ERROR, AND NOT ALWAYS A DEFECT. `users` is RLS'd on a JOIN — a row is visible to
                // the acting user or to an ACTIVE co-tenant — so under a tenant-only context the extract
                // contains exactly the workspace's ACTIVE members. Three shapes land here legitimately: an
                // outstanding invitation (`tenant_users` row, no active membership), a removed member whose
                // authored forms and submissions remain, and the platform operator named by
                // `impersonation_tokens.operator_id`, who is staff and belongs to no tenant at all. Reported
                // per column so a reader can tell those apart instead of concluding the extract is corrupt.
                'note' => 'Ids referenced by extracted rows that the extract does not contain, because the '
                    .'`users` SELECT policy admits only the workspace\'s active members. Expected for '
                    .'outstanding invitations, removed members and platform operators.',
                'by_column' => $this->unresolvedUserReferences,
            ],
            'not_extracted' => TenantExtractColumns::NOT_EXTRACTED,
        ];
    }
}
