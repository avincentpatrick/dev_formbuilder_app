<?php

declare(strict_types=1);

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
| The CHECK constraints and unique keys on scope_nodes / resource_grants (Increment G10a), exercised with
| raw DB:: writes so they are proven at the DATABASE rather than in the enum casts that normally front them.
| Each is a backstop for a specific way the application layer could be bypassed or wrong.
|
| PostgreSQL aborts the surrounding transaction on ANY error, and RefreshDatabase wraps the whole test in
| one — so a test that asserts a throw issues no further statements. Each rejection gets its own test.
|
| `$this->grantee` is deliberately NOT the form's creator: FormService::create already writes an editor
| grant for the creator, which would collide with these fixtures on the (target, user) unique key.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    $this->grantee = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->form = app(FormService::class)->create($this->tenant, $this->user, 'Survey');
    $this->node = makeScopeNode(name: 'Region I');
});

/** @return array<string, mixed> */
function rawGrant(array $overrides = []): array
{
    return array_merge([
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => test()->tenant->id,
        'scopeable_type' => 'form',
        'scopeable_id' => test()->form->id,
        'user_id' => test()->grantee->id,
        'capacity' => 'editor',
        'includes_descendants' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

it('rejects a scopeable_type outside the registered morph aliases', function (): void {
    // This is what makes the RLS guard's per-alias branch set provably EXHAUSTIVE: an unregistered type
    // cannot be stored, so it can never fall through every branch and land unguarded.
    expect(fn () => DB::table('resource_grants')->insert(rawGrant(['scopeable_type' => 'tenant'])))
        ->toThrow(QueryException::class);
});

it('rejects a capacity outside the enum', function (): void {
    expect(fn () => DB::table('resource_grants')->insert(rawGrant(['capacity' => 'superuser'])))
        ->toThrow(QueryException::class);
});

it('rejects includes_descendants on a form-targeted grant', function (): void {
    // "Include descendants" is meaningless on a form. Forbidding it keeps every row's blast radius
    // legible from the row itself, rather than depending on the reader knowing it is ignored.
    expect(fn () => DB::table('resource_grants')->insert(rawGrant(['includes_descendants' => true])))
        ->toThrow(QueryException::class);
});

it('allows includes_descendants on a node-targeted grant', function (): void {
    DB::table('resource_grants')->insert(rawGrant([
        'scopeable_type' => 'scope_node',
        'scopeable_id' => $this->node->id,
        'includes_descendants' => true,
    ]));

    expect(DB::table('resource_grants')->where('includes_descendants', true)->count())->toBe(1);
});

it('permits one grant per (target, user) — capacity is NOT part of the key', function (): void {
    // RBAC §8: a person holds exactly one capacity per target. If capacity were in the unique key, a
    // "demotion" from editor to reviewer would INSERT a second row and silently leave edit rights live.
    DB::table('resource_grants')->insert(rawGrant());

    expect(fn () => DB::table('resource_grants')->insert(rawGrant(['capacity' => 'reviewer'])))
        ->toThrow(QueryException::class);
});

it('permits the same user to hold grants on different targets', function (): void {
    DB::table('resource_grants')->insert(rawGrant());
    DB::table('resource_grants')->insert(rawGrant([
        'scopeable_type' => 'scope_node',
        'scopeable_id' => $this->node->id,
    ]));

    // Scoped to the grantee: the form's CREATOR also holds a grant from FormService::create.
    expect(DB::table('resource_grants')->where('user_id', $this->grantee->id)->count())->toBe(2);
});

it('rejects a scope node deeper than the depth cap', function (): void {
    expect(fn () => DB::table('scope_nodes')->insert([
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => $this->tenant->id,
        'name' => 'Too deep',
        'path' => '/x/',
        'depth' => 13, // cap is 12, which bounds `path` inside its varchar(512)
        'position' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a node that is its own parent', function (): void {
    $id = Uuid::uuid7()->toString();

    expect(fn () => DB::table('scope_nodes')->insert([
        'id' => $id,
        'tenant_id' => $this->tenant->id,
        'parent_id' => $id,
        'name' => 'Ouroboros',
        'path' => '/'.$id.'/',
        'depth' => 0,
        'position' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a duplicate node code within one tenant', function (): void {
    makeScopeNode(name: 'Region I', attributes: ['code' => 'R1']);

    expect(fn () => makeScopeNode(name: 'Duplicate', attributes: ['code' => 'R1']))
        ->toThrow(QueryException::class);
});

it('allows the same node code in a different tenant', function (): void {
    // These are the TENANT's own opaque codes — the platform never interprets them, so two tenants
    // both using 'R1' is expected, not a collision.
    makeScopeNode(name: 'Region I', attributes: ['code' => 'R1']);

    $other = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo', 'default_locale' => 'en']);
    enterTenant($other->id, $this->user->id);

    expect(makeScopeNode(name: 'Bravo Region I', attributes: ['code' => 'R1'])->code)->toBe('R1');
});
