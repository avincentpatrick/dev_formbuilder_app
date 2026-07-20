<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Models\ResourceGrant;
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
| Node lifecycle + the blast-radius counters (Increment G10b).
|
| Deactivation and deletion are the two branch-wide revocation controls, so most of what matters here is
| not "did the row change" but "did REACH change, on both resolver query shapes, in the same request".
| The same-request part is the `forget()` discipline: the resolver memoizes the inactive-path set and the
| form=>path map per request, and the per-user forget() clears NEITHER.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    makeActiveMember($this->user, 'form_editor');

    $this->service = app(ScopeNodeService::class);
    $this->resolver = app(ResourceGrantResolver::class);
});

// ── update ───────────────────────────────────────────────────────────────────────────────────────────

it('updates labels without touching the tree structure', function (): void {
    $node = makeScopeNode(name: 'Region I', attributes: ['code' => 'R1', 'node_type' => 'region']);
    $pathBefore = $node->path;

    $updated = $this->service->update($node, ['name' => 'Region One', 'code' => 'R01', 'position' => 3]);

    expect($updated->fresh()->name)->toBe('Region One')
        ->and($updated->fresh()->code)->toBe('R01')
        ->and($updated->fresh()->position)->toBe(3)
        ->and($updated->fresh()->path)->toBe($pathBefore);
});

it('ignores structural columns handed to update()', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $other = makeScopeNode(name: 'Region II');
    $child = makeScopeNode($root, 'Province A');

    // update() filters its input rather than trusting the caller — the model guard is the backstop, but a
    // service that forwards arbitrary attributes would be relying on it for a routine request payload.
    $this->service->update($child, ['name' => 'Renamed', 'parent_id' => $other->id, 'depth' => 9]);

    expect($child->fresh()->parent_id)->toBe($root->id)
        ->and($child->fresh()->depth)->toBe(1)
        ->and($child->fresh()->name)->toBe('Renamed');
});

// ── deactivation cuts the branch ─────────────────────────────────────────────────────────────────────

it('cuts an entire subtree when an intermediate node is deactivated, in the same request', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $province = makeScopeNode($root, 'Province A');
    $city = makeScopeNode($province, 'City X');
    $form = formIn($city);

    $grantee = User::factory()->create();
    makeActiveMember($grantee, 'form_editor');
    grantOnNode($root, $grantee, ResourceCapacity::Editor, descendants: true);

    expect($this->resolver->holds($grantee, $form, ResourceCapacity::Editor))->toBeTrue();

    // Deactivating the MIDDLE node must cut the leaf beneath it. This is the exact bug the G10a
    // walkthrough caught, and it is the reason setActive() clears the whole memo rather than one user's.
    $this->service->setActive($province, false);

    expect($this->resolver->holds($grantee, $form->fresh(), ResourceCapacity::Editor))->toBeFalse()
        ->and($this->resolver->grantedFormIdsQuery($grantee)->pluck('id')->all())->not->toContain($form->id);
});

it('restores reach when the branch is reactivated', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $province = makeScopeNode($root, 'Province A');
    $form = formIn($province);

    $grantee = User::factory()->create();
    makeActiveMember($grantee, 'form_editor');
    grantOnNode($root, $grantee, ResourceCapacity::Editor, descendants: true);

    $this->service->setActive($province, false);
    expect($this->resolver->holds($grantee, $form->fresh(), ResourceCapacity::Editor))->toBeFalse();

    $this->service->setActive($province, true);
    expect($this->resolver->holds($grantee, $form->fresh(), ResourceCapacity::Editor))->toBeTrue();
});

// ── deletion ─────────────────────────────────────────────────────────────────────────────────────────

it('cascades a delete to descendants and un-scopes their forms', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $province = makeScopeNode($root, 'Province A');
    $form = formIn($province);

    $this->service->delete($root);

    expect(ScopeNode::query()->whereKey($province->id)->exists())->toBeFalse()
        // The column-list SET NULL un-scopes the form without deleting it or nulling its tenant_id.
        ->and($form->fresh()->scope_node_id)->toBeNull()
        ->and($form->fresh()->tenant_id)->toBe($this->tenant->id);
});

it('deletes grants on the whole deleted subtree rather than orphaning them', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $province = makeScopeNode($root, 'Province A');

    $grantee = User::factory()->create();
    makeActiveMember($grantee, 'form_editor');
    grantOnNode($root, $grantee, ResourceCapacity::Editor);
    grantOnNode($province, $grantee, ResourceCapacity::Reviewer);

    expect(ResourceGrant::query()->count())->toBe(2);

    // A morph has no FK, so nothing cascades these. Left behind they are invisible authorization rows
    // pointing at ids that no longer exist — so the writer that creates them cleans them up.
    $this->service->delete($root);

    expect(ResourceGrant::query()->count())->toBe(0);
});

it('leaves grants outside the deleted subtree alone', function (): void {
    $doomed = makeScopeNode(name: 'Region I');
    $survivor = makeScopeNode(name: 'Region II');

    $grantee = User::factory()->create();
    makeActiveMember($grantee, 'form_editor');
    grantOnNode($doomed, $grantee, ResourceCapacity::Editor);
    grantOnNode($survivor, $grantee, ResourceCapacity::Editor);

    $this->service->delete($doomed);

    expect(ResourceGrant::query()->count())->toBe(1)
        ->and(ResourceGrant::query()->first()?->scopeable_id)->toBe($survivor->id);
});

it('reports what a deletion would take with it', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $province = makeScopeNode($root, 'Province A');
    $city = makeScopeNode($province, 'City X');
    formIn($root, 'At the root');
    formIn($city, 'Deep down');

    $grantee = User::factory()->create();
    makeActiveMember($grantee, 'form_editor');
    grantOnNode($province, $grantee, ResourceCapacity::Reviewer);

    expect($this->service->deletionImpact($root))->toBe([
        'forms' => 2,
        'nodes' => 2,   // province + city, excluding the node itself
        'grants' => 1,
    ]);
});

// ── blast radius ─────────────────────────────────────────────────────────────────────────────────────

it('splits node reach into direct and descendant counts', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $province = makeScopeNode($root, 'Province A');
    $city = makeScopeNode($province, 'City X');

    formIn($root, 'At root');
    formIn($province, 'One down');
    formIn($city, 'Two down');

    // The split IS the escalation control: `direct` is what a plain grant confers, `descendant` is the
    // extra reach the includes_descendants checkbox buys.
    expect($this->resolver->nodeReach($root))->toBe(['direct' => 1, 'descendant' => 2])
        ->and($this->resolver->nodeReach($province))->toBe(['direct' => 1, 'descendant' => 1])
        ->and($this->resolver->nodeReach($city))->toBe(['direct' => 1, 'descendant' => 0]);
});

it('reports zero reach for a deactivated node rather than advertising access it cannot confer', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $child = makeScopeNode($root, 'Province A');
    formIn($root);
    formIn($child);

    $this->service->setActive($root, false);

    // A grant here resolves to nothing, so a preview reporting "2 forms" would be a lie an administrator
    // makes an escalation decision on.
    expect($this->resolver->nodeReach($root))->toBe(['direct' => 0, 'descendant' => 0]);
});

it('excludes forms under a deactivated descendant branch from the descendant count', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $live = makeScopeNode($root, 'Province A');
    $dead = makeScopeNode($root, 'Province B');
    formIn($live);
    formIn($dead);

    $this->service->setActive($dead, false);

    expect($this->resolver->nodeReach($root))->toBe(['direct' => 0, 'descendant' => 1]);
});

it('agrees with what the grant actually confers', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $province = makeScopeNode($root, 'Province A');
    formIn($root);
    formIn($province);
    formIn(makeScopeNode($province, 'City X'));

    $grantee = User::factory()->create();
    makeActiveMember($grantee, 'form_editor');

    $reach = $this->resolver->nodeReach($root);
    grantOnNode($root, $grantee, ResourceCapacity::Editor, descendants: true);

    // The keystone: the number the preview shows must equal the number the grant then delivers, measured
    // through the resolver's own list twin. A preview computed by a second, parallel query is how those
    // two drift apart.
    expect($this->resolver->grantedFormIdsQuery($grantee)->count())
        ->toBe($reach['direct'] + $reach['descendant']);
});

it('counts only the node itself when descendants are not included', function (): void {
    $root = makeScopeNode(name: 'Region I');
    formIn($root);
    formIn(makeScopeNode($root, 'Province A'));

    $grantee = User::factory()->create();
    makeActiveMember($grantee, 'form_editor');

    $reach = $this->resolver->nodeReach($root);
    grantOnNode($root, $grantee, ResourceCapacity::Editor, descendants: false);

    expect($this->resolver->grantedFormIdsQuery($grantee)->count())->toBe($reach['direct'])
        ->and($reach['descendant'])->toBe(1);
});
