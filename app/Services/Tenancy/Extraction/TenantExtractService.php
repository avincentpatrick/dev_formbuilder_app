<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Extraction;

use App\Enums\ExtractFilter;
use App\Exceptions\Tenancy\TenantExtractException;
use App\Models\Tenant;
use App\Support\Tenancy\ExtractionGuard;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantExtractColumns;
use App\Support\Tenancy\TenantScopedTables;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads one tenant's entire record out of the shared database (Phase 4, P2b — ADR-0018).
 *
 * This is the first of the three deliverables ADR-0002's "Future migration path" deferred and ADR-0017
 * declined to build without answers to two questions. Both are answered here, and both are answered as
 * DATA rather than as behaviour buried in a loop:
 *
 *   - **What does the extract contain for `users`?** A membership roster and nothing else —
 *     {@see TenantExtractColumns::WITHHELD}`['users']` withholds nine columns, every one of them either a
 *     live credential for a CENTRAL identity or a fact about a subject that is not this tenant. What the
 *     roster cannot reach is reported rather than dropped: see the reconciliation below.
 *   - **Are platform-shared rows in or out?** Out, and counted. The six widened tables are read under an
 *     explicit `tenant_id = ?` on top of RLS, because {@see TenantScopedTables::rlsReturnsSuperset()} says
 *     RLS over-selects there.
 *
 * ── THE FOUR THINGS THAT MAKE A CLEAN RUN MEANINGLESS, AND WHAT STOPS EACH ──────────────────────────────
 *  1. **The wrong role.** On `meridian` (SUPERUSER, BYPASSRLS) every policy is ignored and the extract is
 *     a full-database dump wearing one tenant's name. {@see ExtractionGuard::assertRlsSubjectRole()}.
 *  2. **No context.** `applyLocal()` outside a transaction is a silent `SET LOCAL` no-op; every policy
 *     then matches zero rows and the run succeeds over an empty database.
 *     {@see ExtractionGuard::assertContextEstablished()}, which reads the GUC BACK.
 *  3. **A renamed secret.** A withheld entry that matches no column filters nothing.
 *     {@see TenantExtractColumns::assertWithheldColumnsExist()}.
 *  4. **Torn reads.** 41 tables read outside one snapshot describe 41 different instants, and a submission
 *     whose answers arrive without its parent version is not a smaller extract, it is a corrupt one. The
 *     whole run is one REPEATABLE READ transaction, and the manifest reports the isolation level that was
 *     ACTUALLY in force rather than the one requested.
 *
 * ⚠️ THERE IS NO HTTP SURFACE AND THAT IS A DECISION (ADR-0018 §D1). A route would need a permission key,
 * which is an authorization widening, and would put a whole-workspace read — credentials excepted — behind
 * whatever the weakest session on that route turns out to be. The artefact is produced on the box by
 * somebody who already has the box.
 */
final class TenantExtractService
{
    /**
     * How many rows are held in memory at once. The bound that matters is not the row count but the widest
     * row: `form_versions.schema_snapshot` and `submission_answers.answers` are unbounded jsonb documents,
     * so 1,000 of them is already megabytes. Kept deliberately modest for that reason rather than tuned up
     * to reduce round trips.
     */
    private const int CHUNK = 500;

    public function extract(Tenant $tenant, ExtractWriter $writer): ExtractManifest
    {
        $previousTenant = TenantContext::currentTenantId();
        $previousUser = TenantContext::currentUserId();

        try {
            return DB::transaction(fn (): ExtractManifest => $this->run($tenant, $writer));
        } finally {
            // ⚠️ applyLocal(), never forget(). forget() is SESSION-scoped, so it survives a surrounding
            // transaction's rollback and bleeds the cleared context into whatever runs next — which under
            // RefreshDatabase is the following test. Restoring the PREVIOUS values rather than clearing
            // them is what makes this callable from a request that already had a context.
            TenantContext::applyLocal($previousTenant, $previousUser);
        }
    }

    private function run(Tenant $tenant, ExtractWriter $writer): ExtractManifest
    {
        // Must be the first statement of the transaction — PostgreSQL refuses it once any query has run.
        // Skipped when this is a nested transaction, which in practice means a test under RefreshDatabase:
        // the outer transaction has already executed statements, so the level is inherited and not chosen.
        // The manifest therefore reports what was read back, not what was asked for.
        if (DB::transactionLevel() === 1) {
            DB::statement('set transaction isolation level repeatable read');
        }

        ExtractionGuard::assertRlsSubjectRole();
        TenantContext::applyLocal($tenant->id);
        ExtractionGuard::assertContextEstablished($tenant->id);

        $schema = ExtractSchema::read();
        $writer->prepare();

        $reports = [];
        /** @var array<string, array<string, true>> $referencedUsers "table.column" => set of ids */
        $referencedUsers = [];
        /** @var array<string, true> $extractedUserIds */
        $extractedUserIds = [];

        // `users` LAST, so the reconciliation below has both sides in hand. Nothing else depends on order.
        $plan = [...$this->tablePlan(), 'users' => ExtractFilter::RlsUserJoin];

        foreach ($plan as $table => $filter) {
            $reports[] = $this->extractTable(
                $table, $filter, $tenant, $schema, $writer, $referencedUsers, $extractedUserIds
            );
        }

        $manifest = new ExtractManifest(
            tenant: [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
            ],
            generatedAt: CarbonImmutable::now()->toIso8601String(),
            snapshot: $this->snapshot(),
            tables: $reports,
            unresolvedUserReferences: $this->reconcileUserReferences($referencedUsers, $extractedUserIds),
        );

        $writer->writeManifest($manifest->toArray());

        return $manifest;
    }

    /**
     * Which tables are read and under which filter, derived from {@see TenantScopedTables} rather than
     * re-listed. `rlsReturnsSuperset()` is what decides whether a table needs the explicit predicate, so
     * classifying a new table there is the whole of the work when one is added — there is no second list
     * here to forget.
     *
     * @return array<string, ExtractFilter>
     */
    private function tablePlan(): array
    {
        $plan = [];

        foreach (TenantScopedTables::all() as $table) {
            $plan[$table] = TenantScopedTables::rlsReturnsSuperset($table)
                ? ExtractFilter::RlsAndPredicate
                : ExtractFilter::Rls;
        }

        foreach (array_keys(TenantScopedTables::UNPROTECTED_TENANT_TABLES) as $table) {
            $plan[$table] = ExtractFilter::PredicateOnly;
        }

        // The workspace's own row: name, slug, locales, branding, draft TTL, maintenance state. It carries
        // no `tenant_id` because it IS the tenant, so the predicate is on the primary key.
        $plan['tenants'] = ExtractFilter::PredicateOnly;

        ksort($plan);

        return $plan;
    }

    /**
     * @param  array<string, array<string, true>>  $referencedUsers
     * @param  array<string, true>  $extractedUserIds
     *
     * @throws TenantExtractException
     */
    private function extractTable(
        string $table,
        ExtractFilter $filter,
        Tenant $tenant,
        ExtractSchema $schema,
        ExtractWriter $writer,
        array &$referencedUsers,
        array &$extractedUserIds,
    ): ExtractedTable {
        $catalogColumns = $schema->columnsOf($table);
        TenantExtractColumns::assertWithheldColumnsExist($table, $catalogColumns);

        $verbatim = TenantExtractColumns::verbatimFor($table, $catalogColumns);
        $transformed = TenantExtractColumns::transformedFor($table);

        $types = [];
        foreach ($verbatim as $column) {
            $types[$column] = $schema->typeOf($table, $column) ?? 'text';
        }
        foreach ($transformed as $column => $spec) {
            $types[$column] = $spec['type'];
        }

        $encoder = new ExtractRowEncoder($types);
        $userColumns = array_values(array_intersect($schema->userReferencesIn($table), array_keys($types)));

        $writer->openTable($table);
        $rows = 0;

        $query = $this->query($table, $filter, $tenant, $schema, $verbatim, $transformed);
        $primaryKey = $schema->primaryKeyOf($table);

        // ⚠️ NOT `->cursor()`, WHICH WOULD BOUND NOTHING HERE. `pdo_pgsql` buffers a whole result set in
        // libpq before PHP sees its first row unless a server-side cursor is declared, so `cursor()` reads
        // as streaming and materialises the table. `lazyById()` and `lazy()` issue real LIMITed queries.
        // Keyset paging is preferred where the key is a single column — offset paging re-scans everything
        // it has already skipped, which on the one unbounded table here is the whole point of chunking.
        $records = count($primaryKey) === 1
            ? $query->lazyById(self::CHUNK, $primaryKey[0])
            : $query->lazy(self::CHUNK);

        try {
            foreach ($records as $record) {
                $row = $encoder->encode((array) $record);
                $writer->writeRow($row);
                $rows++;

                foreach ($userColumns as $column) {
                    if (($id = $row[$column] ?? null) !== null) {
                        $referencedUsers["{$table}.{$column}"][(string) $id] = true;
                    }
                }

                if ($table === 'users') {
                    $extractedUserIds[(string) $row['id']] = true;
                }
            }
        } finally {
            $writer->closeTable();
        }

        return new ExtractedTable(
            name: $table,
            filter: $filter,
            rows: $rows,
            columns: [...$verbatim, ...array_keys($transformed)],
            withheld: TenantExtractColumns::withheldFor($table),
            platformRowsExcluded: $filter === ExtractFilter::RlsAndPredicate
                ? $this->countPlatformRows($table)
                : null,
        );
    }

    /**
     * @param  list<string>  $verbatim
     * @param  array<string, array{sql: string, type: string}>  $transformed
     */
    private function query(
        string $table,
        ExtractFilter $filter,
        Tenant $tenant,
        ExtractSchema $schema,
        array $verbatim,
        array $transformed,
    ): Builder {
        $grammar = DB::connection()->getQueryGrammar();

        $selects = array_map(static fn (string $c): string => $grammar->wrap($c), $verbatim);

        foreach ($transformed as $column => $spec) {
            $selects[] = $spec['sql'].' as '.$grammar->wrap($column);
        }

        $query = DB::table($table)->selectRaw(implode(', ', $selects));

        // ⚠️ The predicate on the widened six is what makes the platform catalog stay behind, and the
        // predicate on `domains`/`tenants` is the ONLY isolation those two have. Only ExtractFilter::Rls
        // and ::RlsUserJoin are safe without one.
        match ($filter) {
            ExtractFilter::RlsAndPredicate, ExtractFilter::PredicateOnly => $query->where(
                $table === 'tenants' ? 'id' : 'tenant_id',
                $tenant->id
            ),
            ExtractFilter::Rls, ExtractFilter::RlsUserJoin => null,
        };

        // Deterministic order so two extracts of an unchanged tenant are byte-identical, and so the cursor
        // below has a defined sequence. Every table in this schema has a primary key (verified against the
        // catalog); the fallback exists so a future table without one degrades to "unordered" rather than
        // to a fatal.
        foreach ($schema->primaryKeyOf($table) as $keyColumn) {
            $query->orderBy($keyColumn);
        }

        return $query;
    }

    /**
     * How many rows the explicit predicate kept out of a widened table.
     *
     * A SECOND QUERY, not `total − extracted`. Arithmetic would agree with the extract by construction and
     * so could never disagree with it — including in the case worth catching, where the predicate did not
     * apply at all and "excluded" computes to zero because everything came through.
     */
    private function countPlatformRows(string $table): int
    {
        return DB::table($table)->whereNull('tenant_id')->count();
    }

    /**
     * @param  array<string, array<string, true>>  $referenced
     * @param  array<string, true>  $extracted
     * @return array<string, list<string>>
     */
    private function reconcileUserReferences(array $referenced, array $extracted): array
    {
        $unresolved = [];

        foreach ($referenced as $column => $ids) {
            $missing = array_keys(array_diff_key($ids, $extracted));

            if ($missing !== []) {
                sort($missing);
                $unresolved[$column] = $missing;
            }
        }

        ksort($unresolved);

        return $unresolved;
    }

    /**
     * Read back, never assumed. `transaction_isolation` in particular: the request for REPEATABLE READ is
     * skipped on a nested transaction, so an extract produced from inside one is a READ COMMITTED artefact
     * and the manifest has to say so.
     *
     * @return array{database: string, role: string, isolation_level: string, application: string}
     */
    private function snapshot(): array
    {
        /** @var object{db: string, role: string, isolation: string} $row */
        $row = DB::selectOne(
            "select current_database() as db, current_user as role,
                    current_setting('transaction_isolation') as isolation"
        );

        return [
            'database' => (string) $row->db,
            'role' => (string) $row->role,
            'isolation_level' => (string) $row->isolation,
            'application' => (string) config('app.name'),
        ];
    }
}
