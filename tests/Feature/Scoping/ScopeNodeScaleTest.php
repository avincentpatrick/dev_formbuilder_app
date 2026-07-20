<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Models\ScopeNode;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Scale properties of the G10a hierarchy (Increment G10a). One file guards THREE invariants that are all
| invisible to correctness tests — each would keep returning right answers while getting quadratically
| slower, or would fall over only on a tenant large enough to matter:
|
|   1. Descendant resolution is bounded by GRANT count, not descendant count.
|   2. The prefix lookup uses the index (which depends entirely on COLLATE "C").
|   3. No descendant id set is ever materialized into a bind-parameter list.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run(); // idempotent, committed on the privileged connection
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    makeActiveMember($this->user, 'form_editor');
    $this->resolver = app(ResourceGrantResolver::class);
});

/**
 * Bulk-insert a wide 3-level tree without paying per-node model overhead. Paths are composed exactly as
 * ScopeNodeService does; ScopeNodePathTest is what proves that composition is right.
 *
 * @return array{root: string, leaf: string, count: int}
 */
function seedTree(int $provinces = 20, int $citiesEach = 20): array
{
    $tenantId = test()->tenant->id;
    $now = now();

    $rootId = Uuid::uuid7()->toString();
    $rows = [[
        'id' => $rootId, 'tenant_id' => $tenantId, 'parent_id' => null, 'name' => 'Region I',
        'path' => '/'.$rootId.'/', 'depth' => 0, 'position' => 0, 'is_active' => true,
        'created_at' => $now, 'updated_at' => $now,
    ]];

    $leaf = null;

    for ($p = 0; $p < $provinces; $p++) {
        $provinceId = Uuid::uuid7()->toString();
        $provincePath = '/'.$rootId.'/'.$provinceId.'/';
        $rows[] = [
            'id' => $provinceId, 'tenant_id' => $tenantId, 'parent_id' => $rootId, 'name' => "Province {$p}",
            'path' => $provincePath, 'depth' => 1, 'position' => $p, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ];

        for ($c = 0; $c < $citiesEach; $c++) {
            $cityId = Uuid::uuid7()->toString();
            $rows[] = [
                'id' => $cityId, 'tenant_id' => $tenantId, 'parent_id' => $provinceId, 'name' => "City {$p}-{$c}",
                'path' => $provincePath.$cityId.'/', 'depth' => 2, 'position' => $c, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ];
            $leaf = $cityId;
        }
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('scope_nodes')->insert($chunk);
    }

    return ['root' => $rootId, 'leaf' => (string) $leaf, 'count' => count($rows)];
}

it('resolves a deep leaf under a root grant with a query count independent of tree size', function (): void {
    $small = seedTree(2, 2);           // 7 nodes
    $smallForm = app(FormService::class)->create($this->tenant, $this->user, 'Small');
    DB::table('forms')->where('id', $smallForm->id)->update(['scope_node_id' => $small['leaf']]);
    grantOnNode(ScopeNode::findOrFail($small['root']), $this->user, ResourceCapacity::Editor, true);

    $this->resolver->forget();
    DB::flushQueryLog();
    DB::enableQueryLog();
    expect($this->resolver->holds($this->user, $smallForm->refresh(), ResourceCapacity::Editor))->toBeTrue();
    $smallQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // A far larger tree, resolved from a fresh memo.
    $big = seedTree(20, 20);           // 421 nodes
    $bigForm = app(FormService::class)->create($this->tenant, $this->user, 'Big');
    DB::table('forms')->where('id', $bigForm->id)->update(['scope_node_id' => $big['leaf']]);
    grantOnNode(ScopeNode::findOrFail($big['root']), $this->user, ResourceCapacity::Editor, true);

    $this->resolver->forget();
    DB::flushQueryLog();
    DB::enableQueryLog();
    expect($this->resolver->holds($this->user, $bigForm->refresh(), ResourceCapacity::Editor))->toBeTrue();
    $bigQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // 60x the nodes, same query count. A design that expanded descendants would scale with tree size.
    expect($bigQueries)->toBe($smallQueries)
        ->and($bigQueries)->toBeLessThanOrEqual(3);
});

it('keeps the two structural facts that make a descendant prefix indexable', function (): void {
    // The descendant lookup is `path LIKE '<ancestor path>%'`. PostgreSQL can rewrite a CONSTANT-prefix
    // LIKE into range quals (`path >= '<prefix>' AND path < '<prefix++>'`) and serve it from a btree —
    // but ONLY when the column's collation is "C". Under any other collation that rewrite is impossible
    // and the predicate degrades to a post-scan filter: same answers, no test failure anywhere else, and
    // a full scan of the tenant's hierarchy on every authorization check.
    //
    // We assert the two structural preconditions rather than the query plan. A plan-shape assertion
    // proved flaky for an honest reason: on a few-hundred-row table the planner is indifferent between
    // this index and `(tenant_id, is_active)`, and tie-breaks unpredictably — so it measures the cost
    // model, not the property. These two facts are what a future migration could actually break.
    $collation = DB::selectOne(
        'select collation_name from information_schema.columns '
        .'where table_name = ? and column_name = ?',
        ['scope_nodes', 'path']
    );
    expect($collation?->collation_name)->toBe('C');

    $index = DB::selectOne(
        'select indexdef from pg_indexes where tablename = ? and indexname = ?',
        ['scope_nodes', 'scope_nodes_tenant_id_path_index']
    );
    // tenant_id must LEAD (ADR-0002 §D1) and `path` must follow it in the same btree, or the prefix
    // range has nothing to range over once the tenant equality is applied.
    expect($index)->not->toBeNull()
        ->and($index->indexdef)->toContain('(tenant_id, path)');
});

it('returns the right descendants at scale, whatever plan the optimiser picks', function (): void {
    // The correctness companion to the structural test above: a wide tree, a mid-level grant, and an
    // exact expected membership. Guards against an over-broad prefix (e.g. forgetting the trailing '/'
    // and matching a sibling whose id merely starts with the same characters).
    $tree = seedTree(20, 20);
    $province = (string) DB::table('scope_nodes')->where('depth', 1)->value('path');

    $matched = DB::table('scope_nodes')->where('path', 'like', $province.'%')->count();

    // The province itself plus exactly its 20 cities — never a sibling branch, never the whole tree.
    expect($matched)->toBe(21)
        ->and($tree['count'])->toBe(421);
});

it('binds a number of parameters bounded by grants, never by descendants', function (): void {
    // PostgreSQL's Bind message caps parameters at 65535. A resolver that materialized descendant ids
    // into a whereIn() would work fine in tests and fail outright on a real region-sized subtree.
    $tree = seedTree(20, 20);
    $form = app(FormService::class)->create($this->tenant, $this->user, 'Big');
    DB::table('forms')->where('id', $form->id)->update(['scope_node_id' => $tree['leaf']]);
    grantOnNode(ScopeNode::findOrFail($tree['root']), $this->user, ResourceCapacity::Editor, true);

    $this->resolver->forget();
    $query = $this->resolver->grantedFormIdsQuery($this->user);

    expect(count($query->getBindings()))->toBeLessThan(20)
        ->and($query->count())->toBeGreaterThanOrEqual(1);
});
