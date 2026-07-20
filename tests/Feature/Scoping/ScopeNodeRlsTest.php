<?php

declare(strict_types=1);

use App\Models\ScopeNode;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| scope_nodes RLS + composite-FK integrity (Increment G10a). Beyond the standard four properties, this
| pins the two COMPOSITE foreign keys — the first literal implementation of ADR-0002 §D5 in the repo.
| They exist because PostgreSQL runs referential actions BYPASSING RLS: without them, one tenant deleting
| its own node performs a cross-tenant write against another tenant's rows.
*/

beforeEach(function (): void {
    TenantContext::flush();
});

/** @return array<string, mixed> */
function nodeRow(string $tenantId, ?string $parentId = null, string $name = 'Region'): array
{
    $id = Uuid::uuid7()->toString();

    return [
        'id' => $id,
        'tenant_id' => $tenantId,
        'parent_id' => $parentId,
        'name' => $name,
        'path' => '/'.$id.'/',
        'depth' => 0,
        'position' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

it('has RLS enabled AND forced on scope_nodes', function (): void {
    $meta = DB::selectOne(
        'select relrowsecurity::int as enabled, relforcerowsecurity::int as forced '
        .'from pg_class where relname = ?',
        ['scope_nodes']
    );

    expect((int) $meta->enabled)->toBe(1);
    expect((int) $meta->forced)->toBe(1);
});

it('never shows one tenant the hierarchy of another', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $b = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo', 'default_locale' => 'en']);

    TenantContext::applyLocal($a->id);
    DB::table('scope_nodes')->insert(nodeRow($a->id, name: 'Alpha region'));

    TenantContext::applyLocal($b->id);
    DB::table('scope_nodes')->insert(nodeRow($b->id, name: 'Bravo region'));

    expect(DB::table('scope_nodes')->pluck('name')->all())->toBe(['Bravo region']);

    TenantContext::applyLocal($a->id);
    expect(DB::table('scope_nodes')->pluck('name')->all())->toBe(['Alpha region']);
});

it('refuses to author a node for another tenant', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $b = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo', 'default_locale' => 'en']);

    TenantContext::applyLocal($a->id);

    expect(fn () => DB::table('scope_nodes')->insert(nodeRow($b->id)))
        ->toThrow(QueryException::class);
});

it('silently updates zero rows when reaching for another tenant\'s node', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $b = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo', 'default_locale' => 'en']);

    // Written under B's own context — NOT pgsql_privileged, which commits outside RefreshDatabase's
    // transaction and so cannot see the `tenants` rows this test just created.
    $row = nodeRow($b->id, name: 'Bravo region');
    TenantContext::applyLocal($b->id);
    DB::table('scope_nodes')->insert($row);

    TenantContext::applyLocal($a->id);

    // The row is simply not there for A: the update matches zero rows rather than erroring.
    expect(DB::table('scope_nodes')->where('id', $row['id'])->update(['name' => 'Hijacked']))->toBe(0);

    TenantContext::applyLocal($b->id);
    expect(DB::table('scope_nodes')->where('id', $row['id'])->value('name'))->toBe('Bravo region');
});

it('raises an FK VIOLATION — not merely an empty read — when parenting onto another tenant\'s node', function (): void {
    // The distinction matters. RLS alone would make the foreign parent invisible, and an unconstrained
    // insert would then create a node with a dangling parent_id whose `path` claims descent from a tree
    // it is not in. The composite FK makes that structurally impossible.
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $b = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo', 'default_locale' => 'en']);

    $foreign = nodeRow($b->id, name: 'Bravo root');
    TenantContext::applyLocal($b->id);
    DB::table('scope_nodes')->insert($foreign);

    TenantContext::applyLocal($a->id);

    expect(fn () => DB::table('scope_nodes')->insert(nodeRow($a->id, $foreign['id'], 'Alpha child')))
        ->toThrow(QueryException::class);
});

it('refuses to assign a form to another tenant\'s scope node', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $b = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo', 'default_locale' => 'en']);
    $user = User::factory()->create();

    $foreign = nodeRow($b->id, name: 'Bravo root');
    TenantContext::applyLocal($b->id);
    DB::table('scope_nodes')->insert($foreign);

    TenantContext::applyLocal($a->id, $user->id);
    $form = app(FormService::class)->create($a, $user, 'Alpha survey');

    expect(fn () => DB::table('forms')->where('id', $form->id)->update(['scope_node_id' => $foreign['id']]))
        ->toThrow(QueryException::class);
});

it('un-scopes a form when its node is deleted, leaving the form and its tenancy intact', function (): void {
    // PG15+ column-list SET NULL. A plain composite nullOnDelete() would try to NULL tenant_id too and
    // fail the NOT NULL constraint on every node delete — so this test is what proves the raw DDL works.
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $user = User::factory()->create();
    TenantContext::applyLocal($a->id, $user->id);

    $node = makeScopeNode(name: 'Region I');
    $form = app(FormService::class)->create($a, $user, 'Alpha survey');
    DB::table('forms')->where('id', $form->id)->update(['scope_node_id' => $node->id]);

    ScopeNode::query()->whereKey($node->id)->delete();

    $reloaded = DB::table('forms')->where('id', $form->id)->first();
    expect($reloaded)->not->toBeNull()
        ->and($reloaded->scope_node_id)->toBeNull()
        ->and($reloaded->tenant_id)->toBe($a->id);
});

it('cascades a node delete to its children', function (): void {
    $a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $user = User::factory()->create();
    TenantContext::applyLocal($a->id, $user->id);

    $root = makeScopeNode(name: 'Root');
    $child = makeScopeNode($root, 'Child');

    ScopeNode::query()->whereKey($root->id)->delete();

    expect(ScopeNode::query()->whereKey($child->id)->exists())->toBeFalse();
});
