<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Models\ResourceGrant;
use App\Models\ScopeNode;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| /api/v1 scoping + grants (Increment G10b).
|
| Real tokens via withToken(), never actingAs() — the Sanctum guard's first-party web-guard loop resolves
| an actingAs() user to a TransientToken that passes every ability check, which would make the ability
| assertions here vacuous (the convention recorded in ApiV1Test).
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);
    enterTenant($this->tenant->id);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    $this->member = User::factory()->create();
    makeActiveMember($this->member, 'form_editor');

    $this->token = $this->admin->createToken('ci', [ApiAbilities::MANAGE_SCOPES])->plainTextToken;
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function api(): string
{
    return 'http://acme.meridian.test/api/v1/';
}

// ── Scope nodes ──────────────────────────────────────────────────────────────────────────────────────

it('creates and lists nodes in tree order', function (): void {
    $root = $this->withToken($this->token)->postJson(api().'scopes', ['name' => 'Region I'])
        ->assertCreated()
        ->assertJsonPath('data.depth', 0)
        ->json('data.id');

    $this->withToken($this->token)
        ->postJson(api().'scopes', ['name' => 'Province A', 'parent_id' => $root])
        ->assertCreated()
        ->assertJsonPath('data.depth', 1)
        ->assertJsonPath('data.parent_id', $root);

    // Ordered by `path`, so a page arrives depth-first and a client renders it without re-sorting.
    $this->withToken($this->token)->getJson(api().'scopes')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Region I')
        ->assertJsonPath('data.1.name', 'Province A');
});

it('moves a node and re-paths its subtree', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    $origin = makeScopeNode(name: 'Region I');
    $destination = makeScopeNode(name: 'Region II');
    $province = makeScopeNode($origin, 'Province A');
    $city = makeScopeNode($province, 'City X');

    $this->withToken($this->token)
        ->postJson(api()."scopes/{$province->id}/move", ['parent_id' => $destination->id])
        ->assertOk()
        ->assertJsonPath('data.parent_id', $destination->id);

    // The request tore down the tenant GUC on its way out; re-enter before reading through RLS.
    enterTenant($this->tenant->id, $this->admin->id);
    expect($city->fresh()->path)->toStartWith($destination->fresh()->path);
});

it('re-roots a node when parent_id is null', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    $child = makeScopeNode(makeScopeNode(name: 'Region I'), 'Province A');

    $this->withToken($this->token)
        ->postJson(api()."scopes/{$child->id}/move", ['parent_id' => null])
        ->assertOk()
        ->assertJsonPath('data.parent_id', null)
        ->assertJsonPath('data.depth', 0);
});

it('returns a 422 envelope for a move that would cycle', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    $root = makeScopeNode(name: 'Region I');
    $descendant = makeScopeNode($root, 'Province A');

    $this->withToken($this->token)
        ->postJson(api()."scopes/{$root->id}/move", ['parent_id' => $descendant->id])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'scope_node_would_cycle');
});

it('reports blast radius and deletion impact', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    $this->user = $this->admin;
    $root = makeScopeNode(name: 'Region I');
    formIn($root, 'At root');
    formIn(makeScopeNode($root, 'Province A'), 'Below');

    $this->withToken($this->token)->getJson(api()."scopes/{$root->id}/impact")
        ->assertOk()
        ->assertJsonPath('data.reach.direct', 1)
        ->assertJsonPath('data.reach.descendant', 1)
        ->assertJsonPath('data.deletion.forms', 2)
        ->assertJsonPath('data.deletion.nodes', 1);
});

it('deletes a node', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    $node = makeScopeNode(name: 'Region I');

    $this->withToken($this->token)->deleteJson(api()."scopes/{$node->id}")->assertNoContent();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(ScopeNode::query()->whereKey($node->id)->exists())->toBeFalse();
});

// ── Grants ───────────────────────────────────────────────────────────────────────────────────────────

it('grants a reviewer capacity over the API — the headline of this increment', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey');

    $this->withToken($this->token)->postJson(api().'resource-grants', [
        'scopeable_type' => 'form',
        'scopeable_id' => $form->id,
        'user_id' => $this->member->id,
        'capacity' => 'reviewer',
    ])
        ->assertCreated()
        ->assertJsonPath('data.capacity', 'reviewer')
        ->assertJsonPath('data.scopeable_type', 'form');
});

it('refuses a self-grant with a 403 envelope', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey');

    $this->withToken($this->token)->postJson(api().'resource-grants', [
        'scopeable_type' => 'form',
        'scopeable_id' => $form->id,
        'user_id' => $this->admin->id,
        'capacity' => 'editor',
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

it('rejects an unknown scopeable_type before it reaches the morph', function (): void {
    $this->withToken($this->token)->postJson(api().'resource-grants', [
        'scopeable_type' => 'tenant',
        'scopeable_id' => $this->tenant->id,
        'user_id' => $this->member->id,
        'capacity' => 'editor',
    ])->assertStatus(422);
});

it('revokes a grant', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    $node = makeScopeNode(name: 'Region I');
    $grant = grantOnNode($node, $this->member, ResourceCapacity::Reviewer);

    $this->withToken($this->token)->deleteJson(api()."resource-grants/{$grant->id}")->assertNoContent();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(ResourceGrant::query()->whereKey($grant->id)->exists())->toBeFalse();
});

// ── Gating ───────────────────────────────────────────────────────────────────────────────────────────

it('refuses a token without the manage:scopes ability', function (): void {
    $readOnly = $this->admin->createToken('ro', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($readOnly)->getJson(api().'scopes')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'insufficient_ability');
});

it('refuses a member who holds the ability but not the permissions', function (): void {
    // This is the assertion that makes the ONE-ability design safe (see ApiAbilities::MANAGE_SCOPES).
    // `manage:scopes` maps to `scopes.manage` OR `forms.collaborators.manage`, so on its own it would be
    // too coarse. It isn't the authorization: the route also runs the policy against the acting user's
    // real permissions. Here a form_editor carries the ability on a hand-minted token and is still refused.
    //
    // Note createToken() does NOT apply ApiAbilities::intersect() — that runs in the mint controller — so
    // this token genuinely carries an ability its holder could not have requested through the API. That
    // makes it the strongest form of this test: the policy gate is what stops it.
    enterTenant($this->tenant->id, $this->member->id);
    $token = $this->member->createToken('ci', [ApiAbilities::MANAGE_SCOPES])->plainTextToken;

    $this->withToken($token)->getJson(api().'scopes')->assertForbidden();
});
