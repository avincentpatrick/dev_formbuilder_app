<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Exceptions\Scoping\ScopeNodeException;
use App\Models\ScopeNode;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Scoping\ScopeNodeService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Re-parenting (Increment G10b). `move()` rewrites `path` for an entire subtree, and `path` IS the
| authorization input — so these tests cover not just "the strings look right" but "who can reach what
| changed correctly, immediately, on BOTH resolver query shapes".
|
| The single-statement re-path fires no Eloquent events, which is exactly why ScopeNode::booted() could be
| kept verbatim. The last test in this file is what proves that guard is still armed.
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

    $this->service = app(ScopeNodeService::class);
    $this->resolver = app(ResourceGrantResolver::class);
});

// ── Path mechanics ───────────────────────────────────────────────────────────────────────────────────

it('re-paths the whole subtree, not just the moved node', function (): void {
    $origin = makeScopeNode(name: 'Region I');
    $destination = makeScopeNode(name: 'Region II');
    $province = makeScopeNode($origin, 'Province A');
    $city = makeScopeNode($province, 'City X');
    $barangay = makeScopeNode($city, 'Barangay 1');

    $this->service->move($province, $destination);

    // Every descendant must sit under the new prefix. A move that only re-paths the moved node leaves its
    // children pointing into a branch that no longer contains them — silently, and only authorization notices.
    expect($province->fresh()->path)->toBe($destination->path.$province->id.'/')
        ->and($city->fresh()->path)->toBe($destination->path.$province->id.'/'.$city->id.'/')
        ->and($barangay->fresh()->path)->toBe($destination->path.$province->id.'/'.$city->id.'/'.$barangay->id.'/')
        ->and(str_starts_with($barangay->fresh()->path, $destination->path))->toBeTrue()
        ->and(str_starts_with($barangay->fresh()->path, $origin->path))->toBeFalse();
});

it('shifts depth by the delta across the subtree', function (): void {
    $origin = makeScopeNode(name: 'Region I');
    $deep = makeScopeNode(makeScopeNode($origin, 'Province A'), 'City X');   // depth 2
    $province = ScopeNode::query()->whereKey($deep->parent_id)->firstOrFail();

    // Province A: depth 1 -> 0 (delta -1). City X follows: depth 2 -> 1.
    $this->service->move($province, null);

    expect($province->fresh()->depth)->toBe(0)
        ->and($deep->fresh()->depth)->toBe(1)
        ->and($province->fresh()->parent_id)->toBeNull()
        ->and($province->fresh()->path)->toBe('/'.$province->id.'/');
});

it('re-parents only the moved node, leaving descendants attached to their own parents', function (): void {
    $destination = makeScopeNode(name: 'Region II');
    $province = makeScopeNode(makeScopeNode(name: 'Region I'), 'Province A');
    $city = makeScopeNode($province, 'City X');

    $this->service->move($province, $destination);

    expect($province->fresh()->parent_id)->toBe($destination->id)
        // The CASE in the re-path statement must touch ONLY the moved node's parent_id.
        ->and($city->fresh()->parent_id)->toBe($province->id);
});

it('leaves sibling branches untouched', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $destination = makeScopeNode(name: 'Region II');
    $moving = makeScopeNode($root, 'Province A');
    $sibling = makeScopeNode($root, 'Province B');
    $siblingChild = makeScopeNode($sibling, 'City Y');

    $before = [$sibling->fresh()->path, $siblingChild->fresh()->path];
    $this->service->move($moving, $destination);

    // The LIKE prefix must not over-match. A sibling shares the parent's prefix but not the moved node's.
    expect([$sibling->fresh()->path, $siblingChild->fresh()->path])->toBe($before);
});

it('is a no-op when the node already has that parent', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $child = makeScopeNode($root, 'Province A');
    $before = $child->fresh()->path;

    expect($this->service->move($child, $root)->path)->toBe($before);
});

// ── Refusals ─────────────────────────────────────────────────────────────────────────────────────────

it('refuses to move a node beneath its own descendant', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $province = makeScopeNode($root, 'Province A');
    $city = makeScopeNode($province, 'City X');

    // Without this check the two rows become mutually ancestral and `path` is unrecoverable — and it is
    // the ONLY thing preventing it, since a CHECK constraint cannot see beyond one row.
    expect(fn () => $this->service->move($root, $city))
        ->toThrow(ScopeNodeException::class, 'beneath itself');

    expect($root->fresh()->path)->toBe('/'.$root->id.'/');
});

it('refuses to move a node onto itself', function (): void {
    $node = makeScopeNode(name: 'Region I');

    // Covered by the same prefix test, because `path` is self-inclusive.
    expect(fn () => $this->service->move($node, $node))->toThrow(ScopeNodeException::class);
});

it('measures the depth cap across the whole subtree, not just the moved node', function (): void {
    // A chain 11 deep (depths 0..10), then move its ROOT under a node at depth 2. The moved node itself
    // would land at depth 3 — fine — but its deepest descendant would land at 13, past the cap of 12.
    $chain = makeScopeNode(name: 'L0');
    $cursor = $chain;
    foreach (range(1, 10) as $i) {
        $cursor = makeScopeNode($cursor, "L{$i}");
    }

    $target = makeScopeNode(makeScopeNode(makeScopeNode(name: 'A'), 'B'), 'C');  // depth 2
    expect($target->depth)->toBe(2);

    expect(fn () => $this->service->move($chain, $target))
        ->toThrow(ScopeNodeException::class, 'past the limit');

    // Refused UPFRONT: nothing moved, and no 23514 escaped from the depth CHECK mid-statement.
    expect($chain->fresh()->path)->toBe('/'.$chain->id.'/');
});

it('rejects the second half of a mutual-move race, because it re-reads after locking', function (): void {
    $a = makeScopeNode(name: 'Region A');
    $b = makeScopeNode(name: 'Region B');

    // The interleaving that made re-parenting unshippable in G10a: two movers, "A under B" and "B under
    // A". Serialized by the tenant advisory lock, the second one runs against the first one's committed
    // result — so its cycle check must see the NEW ancestry, not the ancestry it was called with.
    //
    // Sequential here rather than two live sessions: `RefreshDatabase` wraps each test in an uncommitted
    // transaction, so a second connection cannot see these fixtures at all (documented in
    // ResourceGrantRlsTest). What that costs is proof that the second mover BLOCKS — which
    // ScopeNodeMoveLockingTest establishes separately by asserting the lock is requested before any read.
    // What it keeps is the property that actually prevents corruption: the decision is made on re-read
    // state. A move() that validated against its stale arguments would pass this and corrupt the tree.
    $this->service->move($a, $b);

    expect(fn () => $this->service->move($b->fresh(), $a->fresh()))
        ->toThrow(ScopeNodeException::class, 'beneath itself');

    // And the tree is still coherent: A under B, B still a root.
    expect($a->fresh()->parent_id)->toBe($b->id)
        ->and($b->fresh()->parent_id)->toBeNull();
});

it('still rejects a model-level re-parent after move() ships', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $other = makeScopeNode(name: 'Region II');
    $child = makeScopeNode($root, 'Province A');

    // The whole design rests on this: move() re-paths via the query builder, which fires no model events,
    // so ScopeNode::booted() stays armed for every ordinary save. If this ever starts passing, the
    // structural invariant has degraded into a convention.
    expect(function () use ($child, $other): void {
        $child->parent_id = $other->id;
        $child->save();
    })->toThrow(LogicException::class);
});

// ── Authorization actually follows the move ──────────────────────────────────────────────────────────

it('revokes reach when a form’s branch moves out from under a grant', function (): void {
    $granted = makeScopeNode(name: 'Region I');
    $elsewhere = makeScopeNode(name: 'Region II');
    $province = makeScopeNode($granted, 'Province A');
    $form = formIn($province);

    $grantee = User::factory()->create();
    makeActiveMember($grantee, 'form_editor');
    grantOnNode($granted, $grantee, ResourceCapacity::Editor, descendants: true);

    expect($this->resolver->holds($grantee, $form, ResourceCapacity::Editor))->toBeTrue();

    $this->service->move($province, $elsewhere);

    // Both shapes, in the SAME request — this is what the argument-less forget() in move() buys. A
    // per-user forget() would leave the memoized form=>path map answering with the pre-move path.
    expect($this->resolver->holds($grantee, $form->fresh(), ResourceCapacity::Editor))->toBeFalse()
        ->and($this->resolver->grantedFormIdsQuery($grantee)->pluck('id')->all())->not->toContain($form->id);
});

it('confers reach when a branch moves in under a grant', function (): void {
    $granted = makeScopeNode(name: 'Region I');
    $elsewhere = makeScopeNode(name: 'Region II');
    $province = makeScopeNode($elsewhere, 'Province A');
    $form = formIn($province);

    $grantee = User::factory()->create();
    makeActiveMember($grantee, 'form_editor');
    grantOnNode($granted, $grantee, ResourceCapacity::Editor, descendants: true);

    expect($this->resolver->holds($grantee, $form, ResourceCapacity::Editor))->toBeFalse();

    $this->service->move($province, $granted);

    expect($this->resolver->holds($grantee, $form->fresh(), ResourceCapacity::Editor))->toBeTrue()
        ->and($this->resolver->grantedFormIdsQuery($grantee)->pluck('id')->all())->toContain($form->id);
});

it('keeps a grant on the moved node itself travelling with it', function (): void {
    $elsewhere = makeScopeNode(name: 'Region II');
    $province = makeScopeNode(makeScopeNode(name: 'Region I'), 'Province A');
    $form = formIn($province);

    $grantee = User::factory()->create();
    makeActiveMember($grantee, 'form_editor');
    grantOnNode($province, $grantee, ResourceCapacity::Editor);

    $this->service->move($province, $elsewhere);

    // The grant targets the node by id, so relocating the node must not disturb it.
    expect($this->resolver->holds($grantee, $form->fresh(), ResourceCapacity::Editor))->toBeTrue();
});
