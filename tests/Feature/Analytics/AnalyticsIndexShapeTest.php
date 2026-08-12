<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H24a — the structural guarantees behind ADR-0011 §D7's performance posture.
|
| WHY STRUCTURE AND NOT `EXPLAIN`. The plan was to assert an Index Scan for the canonical series query.
| `tests/Feature/Scoping/ScopeNodeScaleTest.php:120-128` already tried exactly that and rejected it, for an
| honest reason worth repeating rather than rediscovering: on a few-hundred-row test table the planner is
| indifferent between candidate indexes and tie-breaks unpredictably, so a plan-shape assertion measures the
| COST MODEL, not the property. What a future migration can actually break is the index's shape — so that is
| what is pinned here.
|
| Each of these is invisible to every correctness test in the suite: analytics would keep returning right
| answers while scanning, which is precisely Risk R9's failure mode.
*/

/** @return object{indexdef: string}|null */
function analyticsIndexDef(string $name): ?object
{
    /** @var object{indexdef: string}|null $row */
    $row = DB::selectOne(
        'select indexdef from pg_indexes where tablename = ? and indexname = ?',
        ['submissions', $name]
    );

    return $row;
}

it('gives submissions a time-ordered index, which it had none of before H24a', function (): void {
    // ADR-0011 Context 9: `submissions` carried only (tenant_id, form_id), (tenant_id, form_version_id) and
    // (tenant_id, status), so EVERY date-bucketed aggregate was a filtered scan under the RLS predicate.
    $index = analyticsIndexDef('submissions_analytics_series_idx');

    expect($index)->not->toBeNull();

    // tenant_id must LEAD (ADR-0002 §D1) with submitted_at directly behind it, or the date range has nothing
    // to range over once the tenant equality is applied.
    expect($index->indexdef)->toContain('(tenant_id, submitted_at)');
});

it('carries deleted_at IS NULL in the partial predicate, so the scan can stay index-only', function (): void {
    // NOT a micro-optimisation. Without this clause every candidate tuple needs a heap visit to test
    // `deleted_at`, which destroys the index-only scan that is the whole performance argument. It is provable
    // from any `Submission::query()` because the SoftDeletes global scope emits it.
    //
    // The corollary is the rule this test is really defending: NO ANALYTICS QUERY MAY USE `withTrashed()` on
    // `submissions`. Doing so makes the predicate unprovable and silently loses the index.
    $index = analyticsIndexDef('submissions_analytics_series_idx');

    // Postgres re-renders the predicate it stored: `status` is varchar(20), so the comparison round-trips as
    // `(status)::text <> 'draft'::text`.
    expect($index?->indexdef)->toContain('deleted_at IS NULL')
        ->and($index?->indexdef)->toContain("(status)::text <> 'draft'::text");
});

it('carries form_id as an INCLUDE payload rather than a third key column', function (): void {
    // After a range qual a third KEY column is unusable as a search key and only widens the btree key. As an
    // INCLUDE payload it makes "top forms by submission volume in range" — an explicit docs/PRD.md:197
    // deliverable — an index-only GROUP BY.
    $index = analyticsIndexDef('submissions_analytics_series_idx');

    expect($index?->indexdef)->toContain('INCLUDE (form_id)')
        // Guards the distinction: a regression that promoted it to a key column would still contain the
        // substring "form_id", so assert it is NOT in the key tuple.
        ->and($index?->indexdef)->not->toContain('(tenant_id, submitted_at, form_id)');
});

it('widens the H12a capacity index rather than adding a second one', function (): void {
    // `(tenant_id, form_id) WHERE status <> 'draft'` cannot serve a time range at all, so the PRD's PER-FORM
    // series would scan the whole tenant. `(tenant_id, form_id, submitted_at)` is a strict prefix-superset, so
    // FormAcceptanceGuard::assertCapacity()'s hot-path COUNT is unaffected — which is what keeps this ONE
    // index addendum under D7 rather than two.
    $index = analyticsIndexDef('submissions_form_finalized_idx');

    expect($index)->not->toBeNull()
        ->and($index->indexdef)->toContain('(tenant_id, form_id, submitted_at)');
});

it('adds exactly one index to submissions, net', function (): void {
    // D7 authorises ONE index addendum. This is the assertion that keeps a later increment from quietly
    // appending a second (e.g. the value_boolean btree the ADR's Consequences deliberately leaves unbuilt)
    // without re-reading the decision. Update the expected count only alongside a documented decision.
    //
    // Pre-H24a baseline was 7 (pkey, the client-uuid unique, three plain composites, draft-expiry,
    // form-finalized); H24a widens one of those in place and adds exactly one, so 8.
    //
    // ⚠️ 8 → 9 IN J2e, AND THIS GUARD WORKED EXACTLY AS INTENDED. The ninth is
    // `submissions_tenant_id_reference_unique` on `(tenant_id, reference)` — a CORRECTNESS constraint (the
    // short handle must be unique per tenant) rather than an analytics read-path index, so it is outside
    // ADR-0011 §D7's budget rather than an exception to it. Recorded here because the next author to see
    // this number move deserves to know which of the two kinds they are looking at.
    //
    // Worth noting how this was caught: `tests/Feature/Analytics` is one of the directories the container's
    // truncating `RecursiveDirectoryIterator` drops, so no local Pest run could collect this file at all —
    // CI was genuinely the only thing that could see it.
    $count = DB::table('pg_indexes')->where('tablename', 'submissions')->count();

    expect($count)->toBe(9);
});
