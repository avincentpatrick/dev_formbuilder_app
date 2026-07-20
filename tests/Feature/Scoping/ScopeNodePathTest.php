<?php

declare(strict_types=1);

use App\Models\ScopeNode;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The materialized path (Increment G10a). `path` IS the authorization input — the resolver decides access
| by prefix-matching it — so these tests guard its construction, its immutability, and the fact that it
| cannot be supplied from outside ScopeNodeService.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
});

it('gives a root node a self-inclusive path at depth zero', function (): void {
    $root = makeScopeNode(name: 'Region I');

    // Self-INCLUSIVE is the load-bearing part: it lets the resolver treat "granted on this node" and
    // "granted on an ancestor" as one prefix comparison rather than two different queries.
    expect($root->path)->toBe('/'.$root->id.'/')
        ->and($root->depth)->toBe(0)
        ->and($root->parent_id)->toBeNull();
});

it('composes a child path from its parent, incrementing depth', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $child = makeScopeNode($root, 'Province A');

    expect($child->path)->toBe($root->path.$child->id.'/')
        ->and($child->depth)->toBe(1)
        ->and(str_starts_with($child->path, $root->path))->toBeTrue();
});

it('composes correctly three levels deep', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $province = makeScopeNode($root, 'Province A');
    $city = makeScopeNode($province, 'City X');

    expect($city->depth)->toBe(2)
        ->and($city->path)->toBe($root->path.$province->id.'/'.$city->id.'/')
        // Every ancestor's path is a prefix of the descendant's — the invariant the resolver relies on.
        ->and(str_starts_with($city->path, $root->path))->toBeTrue()
        ->and(str_starts_with($city->path, $province->path))->toBeTrue();
});

it('does not let a sibling branch masquerade as an ancestor', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $left = makeScopeNode($root, 'Province A');
    $right = makeScopeNode($root, 'Province B');

    expect(str_starts_with($left->path, $right->path))->toBeFalse()
        ->and(str_starts_with($right->path, $left->path))->toBeFalse();
});

it('ignores a caller-supplied path or depth', function (): void {
    // Both are excluded from $fillable precisely so a request payload cannot graft a node onto another
    // branch of the tree and inherit its grants.
    $node = new ScopeNode([
        'name' => 'Forged',
        'path' => '/somebody-elses-branch/',
        'depth' => 9,
    ]);

    expect($node->path)->toBeNull()
        ->and($node->depth)->toBeNull();
});

it('rejects re-parenting outright, since moving a node would invalidate its subtree paths', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $other = makeScopeNode(name: 'Region II');
    $child = makeScopeNode($root, 'Province A');

    expect(function () use ($child, $other): void {
        $child->parent_id = $other->id;
        $child->save();
    })->toThrow(LogicException::class, 're-parenting is not supported');
});

it('rejects a direct path or depth mutation', function (): void {
    $node = makeScopeNode(name: 'Region I');

    expect(function () use ($node): void {
        $node->forceFill(['path' => '/hijacked/'])->save();
    })->toThrow(LogicException::class);
});

it('allows ordinary edits that do not touch the tree structure', function (): void {
    $node = makeScopeNode(name: 'Region I');

    $node->name = 'Region One';
    $node->is_active = false;
    $node->save();

    expect($node->fresh()->name)->toBe('Region One')
        ->and($node->fresh()->is_active)->toBeFalse();
});

it('stores path under the C collation so prefix lookups stay indexable', function (): void {
    // A future migration recreating this column without COLLATE "C" would silently degrade every node
    // lookup to a Seq Scan: correct results, no test failure anywhere else. This pins it.
    $collation = DB::selectOne(
        'select collation_name from information_schema.columns '
        .'where table_name = ? and column_name = ?',
        ['scope_nodes', 'path']
    );

    expect($collation?->collation_name)->toBe('C');
});
