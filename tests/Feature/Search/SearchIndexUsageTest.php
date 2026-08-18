<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Search\SearchPresenter;
use App\Support\Search\SearchTerms;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantIsolation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Can a keyword predicate on an RLS-protected table become an index qual here? (Increment J1b)
|
| ⚠️ THE SUBJECT IS ELIGIBILITY, NOT SPEED — which is why this file is allowed to exist beside two places
| where this repo argued AGAINST plan assertions (`AnalyticsIndexShapeTest` :14-19 and `ScopeNodeScaleTest`
| :128-131). Their argument is correct and inapplicable here, and the difference is worth stating so nobody
| deletes this file on the strength of those comments:
|
|   1. THERE IS NO TIE TO BREAK. Those tests chose between two indexes that could BOTH serve the query,
|      differing only in cost — so on a small table the planner tie-breaks unpredictably and the assertion
|      measures the cost model. Here the question is binary and is decided in `match_clause_to_index()`
|      BEFORE any cost is computed, from `security_level` and `proleakproof`. Statistics do not enter it.
|   2. EVERY ASSERTION IS DIFFERENTIAL. This file never claims "the plan is X". It claims "the plan differs
|      in exactly this way when exactly this one thing changes" — RLS on vs. off, same rows, same session,
|      same statistics, same bindings. Planner indifference applies to both arms and cancels.
|   3. THE COST DIMENSION IS REMOVED OUTRIGHT by the `enable_seqscan = off` case: penalising sequential
|      scans cannot conjure an index qual the security check rejected. If that verdict ever disagrees with
|      the default-GUC verdict, this file is measuring cost and says so instead of passing.
|   4. IT KEEPS THEIR DEVICE TOO — the closing case is a `pg_indexes` structural assertion in exactly their
|      style. This is a superset of the established approach, not a rejection of it.
|
| ── WHAT WAS MEASURED, AND THE DEFECT IT CAUGHT ──────────────────────────────────────────────────────────
| J1b originally shipped a partial GIN index on each search vector, and this file is why they are gone.
| PostgreSQL refuses to promote a non-leakproof clause to an index qual on a relation carrying RLS quals
| (`restriction_is_securely_promotable()`), and `@@` is not leakproof — so on `forms` and `submissions`,
| which are both ENABLE'd AND FORCE'd, the match is always a heap Filter and the GIN was unreachable. Every
| one of J1b's other 59 tests passed over that: identical rows come back, only the plan differs.
|
| Measured on PostgreSQL 17.0.5. The leakproof catalog is a version-scoped fact, so the version is pinned.
|
| ── MUTATIONS RUN AGAINST THIS FILE, AND WHAT THEY REVEALED ──────────────────────────────────────────────
|   1. RE-ADD the partial GIN index to 2026_08_11_000001. **Only the closing structural case reddens; every
|      plan case stays green** — because the index exists and is still not reached. That is the file working
|      as intended, and it is a third independent confirmation of the finding: this suite distinguishes "an
|      index exists" from "an index is used", which is exactly the distinction 59 functional tests could not
|      make.
|   2. `toContain($needle, $message)` — ⚠️ Pest's `toContain()` is VARIADIC, so a message passed as its
|      second argument is asserted as a SECOND NEEDLE. Two cases here were briefly asserting something other
|      than what they read as. Every containment assertion below is therefore spelled
|      `expect(str_contains(...))->toBeTrue/False($message)`, where the second argument really is a message.
|
| ⚠️ NO `ANALYZE` ANYWHERE, DELIBERATELY. `estimate_rel_size()` reads real block counts and derives density
| from tuple width when `reltuples < 0` (always true under `migrate:fresh`), and rows written inside
| RefreshDatabase's uncommitted transaction are physically on the heap — so the estimate tracks the fixture.
| The un-analyzed `@@` selectivity (`DEFAULT_TS_MATCH_SEL` = 0.005) also biases the planner TOWARD an index,
| which is the safe direction for a file whose feared verdict is "unusable". Running ANALYZE would couple
| these plans to the fixture's data distribution — precisely the cost-model coupling the two precedents
| rightly distrusted.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    // The owner holds `forms.edit.any`, so `Form::scopeVisibleTo()` takes its no-op branch and the captured
    // SQL is the clean two-predicate shape whose plan is unambiguous. The Editor branch adds a `whereIn`
    // subquery, which changes the plan's shape without changing the eligibility verdict this file is about.
    app(FormService::class)->create($this->tenant, $this->owner, 'Clinic Intake');
});

/**
 * Bulk-insert wide, searchable forms so the table is big enough for a bitmap path to be the CHEAPER one —
 * which is what makes "the planner did not choose it" mean something. Descriptions are padded to a few
 * hundred bytes of varied lexemes; at a couple of thousand narrow rows the two paths land within noise of
 * each other, which is exactly the indifference `ScopeNodeScaleTest` describes.
 *
 * ⚠️ `search_vector` is omitted from the column list on purpose — Postgres raises
 * "cannot insert a non-DEFAULT value into column" for a GENERATED ALWAYS ... STORED column.
 */
function seedSearchableForms(string $tenantId, string $userId, int $count = 5000): void
{
    $now = now();
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'id' => Uuid::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'default_locale' => 'en',
            'owner_user_id' => $userId,
            'created_by' => $userId,
            // One row in a thousand carries the needle, so the match is selective enough that an index
            // would genuinely win if it were allowed to compete.
            'title' => $i % 1000 === 0 ? 'Clinic Intake '.$i : 'Payroll Register '.$i,
            'description' => str_repeat('lorem ipsum dolor sit amet consectetur adipiscing elit '.$i.' ', 6),
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('forms')->insert($chunk);
    }
}

/**
 * Run $work with the query log on and return the ONE statement matching ($table, $shape) as raw SQL with its
 * bindings already substituted.
 *
 * ⚠️ SELECTION IS BY COMPILED PREFIX, NEVER SUBSTRING. The submissions statement contains `from "forms"`
 * inside its own subquery, so `str_contains($sql, '"forms"')` matches both arms and would silently start
 * explaining the wrong statement. Laravel compiles the select list before the FROM and neither arm puts a
 * subquery in its select list, so the prefix is unambiguous.
 *
 * ⚠️ BINDINGS ARE INTERPOLATED, NOT BOUND. A parameterised `EXPLAIN ... $1` can be planned GENERICALLY, with
 * the parameter as an unestimatable Param — a different planning problem from the one production hits, and
 * one whose answer can depend on how many times the statement has run. Substituting yields a Const, which is
 * what a custom plan sees. Laravel's `substituteBindingsIntoRawSql` is quote-aware, so `rankSql`'s
 * `'{0.1, 0.2, 0.4, 1.0}'` array literal survives intact.
 *
 * @param  'rows'|'count'  $shape
 */
function capturedSearchStatement(Closure $work, string $table, string $shape): string
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $work();
        $log = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }

    // The alias is QUOTED by this Laravel major (`count(*) as "aggregate"`). Pinning the compiled text is
    // the point of the exactly-one rule — if a framework upgrade changes the spelling, this reddens loudly
    // rather than quietly explaining nothing.
    $prefix = $shape === 'count'
        ? 'select count(*) as "aggregate" from "'.$table.'"'
        : 'select "'.$table.'".';

    $matches = array_values(array_filter(
        $log,
        static fn (array $entry): bool => str_starts_with((string) $entry['query'], $prefix)
    ));

    // A "found 0" that does not say what WAS found is a failure message that costs an hour. List the
    // statements the work actually emitted, truncated, so the mismatch is legible at a glance.
    $seen = implode("\n", array_map(
        static fn (array $entry): string => '  '.mb_substr((string) $entry['query'], 0, 120),
        $log
    ));

    expect($matches)->toHaveCount(
        1,
        "expected exactly one {$shape} statement on {$table} (prefix: {$prefix}), found ".count($matches).
        ". Picking \"the first match\" is how a plan test silently starts explaining a different statement.\n".
        "Statements emitted:\n".$seen
    );

    $connection = DB::connection();

    return $connection->getQueryGrammar()->substituteBindingsIntoRawSql(
        (string) $matches[0]['query'],
        $connection->prepareBindings($matches[0]['bindings'])
    );
}

/**
 * EXPLAIN a fully-substituted statement and return a FLAT, depth-first list of plan nodes.
 *
 * `Plans` is the only child key PostgreSQL emits — InitPlan, SubPlan and everything under a Gather all
 * arrive there, distinguished by `Parent Relationship`. So a recursive collector needs no special-casing
 * for parallelism, which matters: a Gather would otherwise bury the real scan one level deeper than a
 * hand-written `['Plans'][0]` walk expects.
 *
 * @return list<array<string, mixed>>
 */
function explainPlanNodes(string $rawSql): array
{
    $row = (array) DB::selectOne('explain (format json, costs on, verbose off) '.$rawSql);
    $tree = json_decode((string) $row['QUERY PLAN'], true, 512, JSON_THROW_ON_ERROR);

    $flat = [];
    $walk = function (array $node) use (&$walk, &$flat): void {
        $children = $node['Plans'] ?? [];
        unset($node['Plans']);
        $flat[] = $node;

        foreach ($children as $child) {
            $walk($child);
        }
    };
    $walk($tree[0]['Plan']);

    return $flat;
}

/**
 * Render the plan as an indented string so a FAILURE PRINTS THE PLAN. Neither `toContain` nor `toBe` shows
 * it otherwise, and a plan test whose failure message omits the plan is nearly useless at 2am in CI.
 *
 * @param  list<array<string, mixed>>  $nodes
 */
function planSummary(array $nodes): string
{
    $lines = [];

    foreach ($nodes as $node) {
        $parts = [(string) ($node['Node Type'] ?? '?')];

        foreach (['Index Name', 'Relation Name'] as $key) {
            if (isset($node[$key])) {
                $parts[] = "[{$key}={$node[$key]}]";
            }
        }

        foreach (['Index Cond', 'Recheck Cond', 'Filter'] as $key) {
            if (isset($node[$key])) {
                $parts[] = "{$key}: {$node[$key]}";
            }
        }

        $lines[] = implode(' ', $parts);
    }

    return implode("\n", $lines);
}

it('is running against the PostgreSQL major this file was measured on', function (): void {
    $row = DB::selectOne("select current_setting('server_version_num')::int as v,
                                 current_setting('enable_seqscan') as seqscan,
                                 current_setting('enable_bitmapscan') as bitmapscan");

    // The leakproof catalog is version-scoped. If a future major flips `ts_match_vq`, the next case goes
    // red and somebody revisits the decision to drop the indexes — which is the point.
    expect($row->v)->toBeGreaterThanOrEqual(170000)
        ->and($row->seqscan)->toBe('on')
        ->and($row->bitmapscan)->toBe('on');
});

it('connects as a role RLS actually constrains, which is this whole file’s premise', function (): void {
    $rows = DB::select("select c.relname, c.relrowsecurity, c.relforcerowsecurity
                          from pg_class c
                         where c.oid in ('public.forms'::regclass, 'public.submissions'::regclass)
                         order by c.relname");

    foreach ($rows as $row) {
        expect($row->relrowsecurity)->toBeTrue("{$row->relname} has RLS disabled")
            // FORCE is the load-bearing half: the app role OWNS these tables, and without FORCE an owner
            // bypasses RLS entirely — every plan below would change and this file would silently pass on a
            // premise that no longer holds.
            ->and($row->relforcerowsecurity)->toBeTrue("{$row->relname} does not FORCE RLS");
    }

    $me = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');
    expect($me->rolsuper)->toBeFalse()->and($me->rolbypassrls)->toBeFalse();
});

it('finds the tsvector match operator is not leakproof, which is what decides everything below', function (): void {
    // Asked through the OPERATOR rather than by function name, so it is a question about the operator the
    // production query actually uses rather than about a name that could be resolved differently.
    $row = DB::selectOne("select p.proname, p.proleakproof
                            from pg_operator o join pg_proc p on p.oid = o.oprcode
                           where o.oprname = '@@'
                             and o.oprleft = 'tsvector'::regtype
                             and o.oprright = 'tsquery'::regtype");

    expect($row->proname)->toBe('ts_match_vq')
        ->and($row->proleakproof)->toBeFalse(
            'ts_match_vq is now leakproof — a GIN index on the search vectors may have become reachable. '.
            'Re-run the rls_probe case and revisit the decision recorded in 2026_08_11_000001.'
        );

    // The same wall governs every alternative someone might reach for, so it is measured here rather than
    // rediscovered: pg_trgm is not an escape hatch, and plain btree comparison is.
    $ops = collect(DB::select("select o.oprname, p.proleakproof
                                 from pg_operator o join pg_proc p on p.oid = o.oprcode
                                where o.oprname in ('~~', '~~*', '=')
                                  and o.oprleft = 'text'::regtype and o.oprright = 'text'::regtype"))
        ->pluck('proleakproof', 'oprname');

    expect($ops['~~'])->toBeFalse('LIKE became leakproof')
        ->and($ops['~~*'])->toBeFalse('ILIKE became leakproof')
        ->and($ops['='])->toBeTrue('text equality stopped being leakproof');
});

it('loses the GIN index the moment the same table is put under RLS, with nothing else changed', function (): void {
    // ⭐ THE MECHANISM, isolated to one variable. Same table, same 5,000 rows, same session, same statistics,
    // same query — the only thing that changes between the two EXPLAINs is whether RLS is on.
    //
    // PostgreSQL DDL is transactional and RefreshDatabase never commits, so this table and its policies roll
    // away with the test (the `PublishedVersionImmutabilityTest:342-352` precedent).
    $tenantId = TenantContext::currentTenantId();

    DB::statement('create table rls_probe (id uuid primary key, tenant_id uuid not null, v tsvector not null)');
    DB::statement("insert into rls_probe (id, tenant_id, v)
                   select gen_random_uuid(), ?::uuid,
                          to_tsvector('simple', 'lorem ipsum dolor sit amet consectetur token' || g)
                     from generate_series(1, 5000) g", [$tenantId]);
    DB::statement('create index rls_probe_v_idx on rls_probe using gin (v)');

    $sql = "select id from rls_probe where v @@ to_tsquery('simple', 'token4242:*')";

    // ⚠️ `str_contains(...)->toBeTrue($message)` rather than `toContain($needle, $message)`: Pest's
    // `toContain()` is VARIADIC, so a "message" passed as its second argument is asserted as a SECOND
    // NEEDLE. That silently changes what several of these cases assert — caught here on the first run.
    $withoutRls = planSummary(explainPlanNodes($sql));
    expect(str_contains($withoutRls, 'rls_probe_v_idx'))->toBeTrue(
        "the probe never used its GIN even WITHOUT RLS, so this case proves nothing:\n".$withoutRls
    );

    // ⚠️ The policy comes from the PRODUCTION generator, not a hand-written approximation. A hand-written
    // `USING (true)` would be folded away by `eval_const_expressions()` before it reached baserestrictinfo,
    // leaving the security level at the ordinary one — the effect would not reproduce and this case would
    // "refute" the finding for an entirely artificial reason.
    foreach (TenantIsolation::strictSql('rls_probe') as $statement) {
        DB::statement($statement);
    }

    $withRls = planSummary(explainPlanNodes($sql));
    expect(str_contains($withRls, 'rls_probe_v_idx'))->toBeFalse(
        "RLS no longer costs the GIN index — the finding recorded in 2026_08_11_000001 may be stale:\n".$withRls
    );
});

it('reaches the same verdict with sequential scans disabled, so the answer is eligibility and not cost', function (): void {
    $tenantId = TenantContext::currentTenantId();

    DB::statement('create table rls_probe (id uuid primary key, tenant_id uuid not null, v tsvector not null)');
    DB::statement("insert into rls_probe (id, tenant_id, v)
                   select gen_random_uuid(), ?::uuid,
                          to_tsvector('simple', 'lorem ipsum dolor sit amet consectetur token' || g)
                     from generate_series(1, 5000) g", [$tenantId]);
    DB::statement('create index rls_probe_v_idx on rls_probe using gin (v)');

    foreach (TenantIsolation::strictSql('rls_probe') as $statement) {
        DB::statement($statement);
    }

    $sql = "select id from rls_probe where v @@ to_tsquery('simple', 'token4242:*')";
    $default = planSummary(explainPlanNodes($sql));

    // Penalising sequential scans cannot make an ineligible clause eligible — the security check runs in
    // `match_clause_to_index()` long before any cost is compared. So the two verdicts MUST agree; if they
    // ever diverge, this file is measuring the cost model and this case is what says so.
    try {
        DB::statement('set local enable_seqscan = off');
        $penalised = planSummary(explainPlanNodes($sql));
    } finally {
        // ⚠️ SET LOCAL is scoped to the transaction, and RefreshDatabase never commits — a leaked
        // `enable_seqscan = off` would silently change every later plan in this same test.
        DB::statement('set local enable_seqscan = on');
    }

    expect(str_contains($penalised, 'rls_probe_v_idx'))->toBe(
        str_contains($default, 'rls_probe_v_idx'),
        "the index verdict changed when only the cost model did:\n--- default ---\n{$default}\n--- penalised ---\n{$penalised}"
    );
});

it('explains the forms query the presenter actually emitted, not a re-encoding of it', function (): void {
    seedSearchableForms($this->tenant->id, $this->owner->id);

    $raw = capturedSearchStatement(
        fn () => app(SearchPresenter::class)->index($this->owner, SearchTerms::parse('clinic'), null),
        'forms',
        'rows'
    );

    // A drift canary BEFORE planning. If someone swaps in `websearch_to_tsquery` or moves the ranking, this
    // says so in one line instead of producing a mysteriously different plan two assertions later.
    expect($raw)->toContain("search_vector @@ to_tsquery('simple'")
        ->and($raw)->toContain('ts_rank(');

    $summary = planSummary(explainPlanNodes($raw));

    // The property: the match is a heap FILTER, never an Index Cond, and no index on the vector is touched.
    expect(str_contains($summary, 'forms_search_vector_idx'))->toBeFalse($summary)
        ->and(str_contains($summary, 'Filter'))->toBeTrue($summary);

    $indexConds = array_filter(
        explainPlanNodes($raw),
        static fn (array $n): bool => str_contains((string) ($n['Index Cond'] ?? ''), 'search_vector')
    );
    expect($indexConds)->toBe([], "the search vector reached an Index Cond after all:\n".$summary);
});

it('bounds the forms scan by the tenant predicate rather than by the keyword', function (): void {
    // The property that SURVIVED the index being dropped, and therefore the one worth defending. The RLS
    // tenant qual sits at the minimum security level by construction, so it IS securely promotable and can
    // be the index condition — which is what keeps the work O(this tenant's forms) instead of O(table).
    seedSearchableForms($this->tenant->id, $this->owner->id);

    $raw = capturedSearchStatement(
        fn () => app(SearchPresenter::class)->index($this->owner, SearchTerms::parse('clinic'), null),
        'forms',
        'rows'
    );

    $nodes = explainPlanNodes($raw);
    $summary = planSummary($nodes);

    $tenantBounded = array_filter(
        $nodes,
        static fn (array $n): bool => str_contains((string) ($n['Index Cond'] ?? ''), 'tenant_id')
    );

    expect($tenantBounded)->not->toBe([], "nothing bounds this scan by tenant_id:\n".$summary);
});

it('explains the count query too, which is the one with no LIMIT to hide behind', function (): void {
    seedSearchableForms($this->tenant->id, $this->owner->id);

    $raw = capturedSearchStatement(
        fn () => app(SearchPresenter::class)->index($this->owner, SearchTerms::parse('clinic'), null),
        'forms',
        'count'
    );

    $summary = planSummary(explainPlanNodes($raw));

    expect(str_contains($summary, 'forms_search_vector_idx'))->toBeFalse($summary);

    $tenantBounded = array_filter(
        explainPlanNodes($raw),
        static fn (array $n): bool => str_contains((string) ($n['Index Cond'] ?? ''), 'tenant_id')
    );
    expect($tenantBounded)->not->toBe([], "the count query is not tenant-bounded either:\n".$summary);
});

it('cannot use a submissions GIN for a second reason that has nothing to do with RLS', function (): void {
    // ⚠️ Pinned so that a future author who rescues the RLS half does NOT expect an index here to return.
    // `generate_bitmap_or_paths()` abandons an entire OR clause the moment one branch yields no index path,
    // and the `form_id IN (<subquery>)` branch is an ANY SubLink nested inside an OR — never pulled up into
    // a semijoin, so it stays a SubPlan, and a SubPlan-bearing clause can never be an index qual.
    $raw = capturedSearchStatement(
        fn () => app(SearchPresenter::class)->index($this->owner, SearchTerms::parse('clinic'), null),
        'submissions',
        'rows'
    );

    $nodes = explainPlanNodes($raw);
    $summary = planSummary($nodes);

    expect(str_contains($summary, 'BitmapOr'))->toBeFalse($summary)
        ->and(str_contains($summary, 'submissions_search_vector_idx'))->toBeFalse($summary);

    // The SubPlan is the mechanism, so assert it is present rather than only asserting the absence of an
    // index — an absence alone would also pass if the whole arm quietly stopped running.
    $subPlans = array_filter(
        $nodes,
        static fn (array $n): bool => ($n['Parent Relationship'] ?? null) === 'SubPlan'
    );
    expect($subPlans)->not->toBe([], "the form-title branch is no longer a SubPlan:\n".$summary);
});

it('ships no index on either search vector, and 2026_08_11_000001 says why', function (): void {
    // The `AnalyticsIndexShapeTest` device, inverted: an assertion of ABSENCE, so a future author cannot
    // re-add a GIN here without a red test sending them to the migration that measured why it is unreachable.
    $indexes = DB::table('pg_indexes')
        ->whereIn('tablename', ['forms', 'submissions'])
        ->where('indexdef', 'ilike', '%search_vector%')
        ->pluck('indexname')
        ->all();

    expect($indexes)->toBe([]);

    // Anti-vacuity: prove the query CAN see indexes on these tables, so an empty result means "no index on
    // the vector" rather than "the catalog query is broken".
    expect(DB::table('pg_indexes')->whereIn('tablename', ['forms', 'submissions'])->count())
        ->toBeGreaterThan(0);
});
