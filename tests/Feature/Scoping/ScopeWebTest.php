<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Enums\TenantUserStatus;
use App\Models\ResourceGrant;
use App\Models\ScopeNode;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Scoping\ScopeNodeService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment G10b2 — the tenant-web scoping hierarchy surface.
|--------------------------------------------------------------------------
| G10b2 adds no new authorization logic — ScopeNodeService, ResourceGrantService and both policies shipped
| CI-proven in G10b1. What is new, and therefore what these tests pin, is the WEB surface over them:
|
|   - every route is gated on scopes.manage / forms.collaborators.manage (Owner + Admin only);
|   - PATCH must route `is_active` to setActive(), because update() hard-filters it out and would
|     otherwise drop a deactivation on the floor while reporting success;
|   - a refused move (cycle / depth cap) comes back as a redirect carrying an error toast, NOT as JSON —
|     bootstrap/app.php keys its API branch on the `api/v1/*` PATH, so a web route always takes the
|     back()->with('toast') arm. That is the whole reason mutations here are Inertia visits, not fetches.
|
| A request tears the tenant GUC down on terminate, so enterTenant() is re-called before any post-request
| assertion that reads through RLS.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
    $this->user = $this->admin;
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function scopeUrl(string $path = ''): string
{
    return 'http://acme.meridian.test/'.ltrim($path, '/');
}

it('renders the hierarchy page in path order with primed counts', function (): void {
    $region = makeScopeNode(null, 'Region I');
    $province = makeScopeNode($region, 'Province A');
    formIn($province, 'Household Survey');
    grantOnNode($region, $this->admin, ResourceCapacity::Reviewer, true);

    $this->actingAs($this->admin)->withoutVite()
        ->get(scopeUrl('scopes'))
        ->assertOk()
        // `false` skips the on-disk page-file check (repo convention — the Pest job's view finder does
        // not resolve raw .vue files).
        ->assertInertia(fn (Assert $page) => $page
            ->component('scopes/Index', false)
            ->has('nodes', 2)
            ->where('nodes.0.name', 'Region I')
            ->where('nodes.0.depth', 0)
            ->where('nodes.0.has_children', true)
            // Per-PARENT sibling set, not the index in the flattened list — the flat-tree defect no
            // automated tool catches, so it is asserted here.
            ->where('nodes.0.setsize', 1)
            ->where('nodes.0.posinset', 1)
            ->where('nodes.0.grant_count', 1)
            ->where('nodes.1.name', 'Province A')
            ->where('nodes.1.depth', 1)
            ->where('nodes.1.has_children', false)
            ->where('nodes.1.form_count', 1)
            ->where('truncated', false)
        );
});

it('excludes the actor from the grant recipient list', function (): void {
    $other = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($other, 'form_editor');

    $this->actingAs($this->admin)->withoutVite()
        ->get(scopeUrl('scopes'))
        ->assertOk()
        // Self is filtered out because ResourceGrantPolicy::grantCapacity refuses a self-grant with an
        // indistinguishable generic 403 — the UI must not be able to ask for it.
        ->assertInertia(fn (Assert $page) => $page
            ->has('recipients', 1)
            ->where('recipients.0.id', (string) $other->id)
        );
});

it('refuses every route to a member without scopes.manage', function (): void {
    $node = makeScopeNode(null, 'Region I');

    $viewer = User::factory()->create();
    enterTenant($this->tenant->id, $viewer->id);
    makeActiveMember($viewer, 'form_editor');

    $this->actingAs($viewer)->withoutVite()->get(scopeUrl('scopes'))->assertForbidden();
    $this->actingAs($viewer)->withoutVite()->post(scopeUrl('scopes'), ['name' => 'X'])->assertForbidden();
    $this->actingAs($viewer)->withoutVite()->get(scopeUrl("scopes/{$node->id}/impact"))->assertForbidden();
    $this->actingAs($viewer)->withoutVite()->get(scopeUrl("scopes/{$node->id}/grants"))->assertForbidden();
    $this->actingAs($viewer)->withoutVite()->patch(scopeUrl("scopes/{$node->id}"), ['name' => 'X'])->assertForbidden();
    $this->actingAs($viewer)->withoutVite()->post(scopeUrl("scopes/{$node->id}/move"), ['parent_id' => null])->assertForbidden();
    $this->actingAs($viewer)->withoutVite()->delete(scopeUrl("scopes/{$node->id}"))->assertForbidden();
    $this->actingAs($viewer)->withoutVite()->post(scopeUrl('resource-grants'), [])->assertForbidden();
});

it('creates a node under a parent', function (): void {
    $region = makeScopeNode(null, 'Region I');

    $this->actingAs($this->admin)->withoutVite()
        ->post(scopeUrl('scopes'), ['name' => 'Province A', 'parent_id' => $region->id])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $child = ScopeNode::query()->where('name', 'Province A')->firstOrFail();
    expect($child->parent_id)->toBe($region->id)
        ->and($child->depth)->toBe(1)
        ->and($child->path)->toBe("{$region->path}{$child->id}/");
});

it('routes is_active on PATCH to setActive rather than dropping it', function (): void {
    $node = makeScopeNode(null, 'Region I');

    $this->actingAs($this->admin)->withoutVite()
        ->patch(scopeUrl("scopes/{$node->id}"), ['name' => 'Region One', 'is_active' => false])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $fresh = ScopeNode::query()->whereKey($node->id)->firstOrFail();
    // ScopeNodeService::update() hard-filters is_active out of its input, so a controller that passed the
    // whole payload to update() would rename the node and silently leave it active.
    expect($fresh->name)->toBe('Region One')
        ->and($fresh->is_active)->toBeFalse();
});

it('moves a node and re-paths its subtree', function (): void {
    $a = makeScopeNode(null, 'Region A');
    $b = makeScopeNode(null, 'Region B');
    $child = makeScopeNode($a, 'Province');

    $this->actingAs($this->admin)->withoutVite()
        ->post(scopeUrl("scopes/{$a->id}/move"), ['parent_id' => $b->id])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $movedChild = ScopeNode::query()->whereKey($child->id)->firstOrFail();
    expect($movedChild->depth)->toBe(2)
        ->and($movedChild->path)->toStartWith($b->path);
});

it('refuses a cycling move through the web branch, as a redirect with an error toast', function (): void {
    $parent = makeScopeNode(null, 'Region I');
    $child = makeScopeNode($parent, 'Province A');

    $response = $this->actingAs($this->admin)->withoutVite()
        ->post(scopeUrl("scopes/{$parent->id}/move"), ['parent_id' => $child->id]);

    // The load-bearing assertion for the whole transport decision: bootstrap/app.php's $isApi closure keys
    // on the `api/v1/*` PATH, and Laravel runs custom render callbacks BEFORE shouldRenderJsonWhen — so a
    // ScopeNodeException on a web route is a 302 carrying a flash toast, even for an XHR. A fetch-based
    // client would follow that redirect, get HTML with a 200, and throw parsing it.
    $response->assertRedirect();
    $response->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'error');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(ScopeNode::query()->whereKey($parent->id)->firstOrFail()->parent_id)->toBeNull();
});

it('deletes a node with its subtree and the grants on it', function (): void {
    $region = makeScopeNode(null, 'Region I');
    $province = makeScopeNode($region, 'Province A');
    grantOnNode($province, $this->admin, ResourceCapacity::Reviewer);

    $this->actingAs($this->admin)->withoutVite()
        ->delete(scopeUrl("scopes/{$region->id}"))
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(ScopeNode::query()->count())->toBe(0)
        // resource_grants is a morph with no FK, so nothing cascades these — ScopeNodeService::delete()
        // clears them in-transaction rather than manufacturing orphaned authorization rows.
        ->and(ResourceGrant::query()->count())->toBe(0);
});

it('returns the blast-radius preview as a bare JSON payload', function (): void {
    $region = makeScopeNode(null, 'Region I');
    $province = makeScopeNode($region, 'Province A');
    formIn($region, 'Direct Survey');
    formIn($province, 'Descendant Survey');

    $this->actingAs($this->admin)->withoutVite()
        ->getJson(scopeUrl("scopes/{$region->id}/impact"))
        ->assertOk()
        // No `data` envelope — matches the forms.library-items sidecar this mirrors.
        ->assertJsonPath('reach.direct', 1)
        ->assertJsonPath('reach.descendant', 1)
        ->assertJsonPath('deletion.forms', 2)
        ->assertJsonPath('deletion.nodes', 1);
});

it('reports zero reach for a deactivated node', function (): void {
    $region = makeScopeNode(null, 'Region I');
    formIn($region, 'Survey');
    app(ScopeNodeService::class)->setActive($region, false);

    $this->actingAs($this->admin)->withoutVite()
        ->getJson(scopeUrl("scopes/{$region->id}/impact"))
        ->assertOk()
        // The resolver refuses a grant under an inactive branch, so the preview must not advertise reach
        // the grant would not confer. The modal turns this into a "reactivate first" notice.
        ->assertJsonPath('reach.direct', 0)
        ->assertJsonPath('reach.descendant', 0);
});

it('grants and revokes access on a node', function (): void {
    $region = makeScopeNode(null, 'Region I');
    $member = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($member, 'form_editor');

    $this->actingAs($this->admin)->withoutVite()
        ->post(scopeUrl('resource-grants'), [
            'scope_node_id' => $region->id,
            'user_id' => $member->id,
            'capacity' => ResourceCapacity::Reviewer->value,
            'includes_descendants' => true,
        ])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $grant = ResourceGrant::query()->where('user_id', $member->id)->firstOrFail();
    expect($grant->capacity)->toBe(ResourceCapacity::Reviewer)
        ->and($grant->includes_descendants)->toBeTrue()
        ->and($grant->scopeable_id)->toBe($region->id);

    $this->actingAs($this->admin)->withoutVite()
        ->delete(scopeUrl("resource-grants/{$grant->id}"))
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(ResourceGrant::query()->whereKey($grant->id)->exists())->toBeFalse();
});

it('replaces rather than accumulates when a capacity is changed', function (): void {
    $region = makeScopeNode(null, 'Region I');
    $member = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($member, 'form_editor');

    foreach ([ResourceCapacity::Editor, ResourceCapacity::Reviewer] as $capacity) {
        $this->actingAs($this->admin)->withoutVite()
            ->post(scopeUrl('resource-grants'), [
                'scope_node_id' => $region->id,
                'user_id' => $member->id,
                'capacity' => $capacity->value,
            ])
            ->assertRedirect();
    }

    enterTenant($this->tenant->id, $this->admin->id);
    // The unique key deliberately excludes `capacity`, so a demotion REPLACES. This is what the grant
    // modal's "replaces their existing Editor grant" copy is describing.
    expect(ResourceGrant::query()->where('user_id', $member->id)->count())->toBe(1)
        ->and(ResourceGrant::query()->where('user_id', $member->id)->firstOrFail()->capacity)
        ->toBe(ResourceCapacity::Reviewer);
});

it('refuses a self-grant', function (): void {
    $region = makeScopeNode(null, 'Region I');

    $this->actingAs($this->admin)->withoutVite()
        ->post(scopeUrl('resource-grants'), [
            'scope_node_id' => $region->id,
            'user_id' => $this->admin->id,
            'capacity' => ResourceCapacity::Reviewer->value,
        ])
        // ResourceGrantPolicy::grantCapacity returns bool, so this is a generic 403 with no distinguishing
        // code. The presenter filters self out of `recipients` precisely so the UI cannot reach this.
        ->assertForbidden();
});

it('404s an invited recipient, because RLS hides them before the service guard runs', function (): void {
    $region = makeScopeNode(null, 'Region I');
    $invited = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $invited->id,
        'status' => TenantUserStatus::Invited,
        'invited_at' => now(),
    ]);

    $this->actingAs($this->admin)->withoutVite()
        ->post(scopeUrl('resource-grants'), [
            'scope_node_id' => $region->id,
            'user_id' => $invited->id,
            'capacity' => ResourceCapacity::Reviewer->value,
        ])
        // NOT GrantException::recipientNotActive() and NOT a toast. The `users_visibility` RLS policy admits
        // only ACTIVE co-tenants plus self, so `exists:users,id` already fails and the controller's
        // firstOrFail() would 404 regardless — the service's assertActiveMember() is defence in depth that
        // no route-reachable path gets to. Asserting the wrong failure here would encode a fiction.
        ->assertStatus(302)
        ->assertSessionHasErrors('user_id');
});

it('lists the grants held on a node with resolved identities', function (): void {
    $region = makeScopeNode(null, 'Region I');
    $member = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($member, 'form_editor');
    grantOnNode($region, $member, ResourceCapacity::Reviewer, true);

    $this->actingAs($this->admin)->withoutVite()
        ->getJson(scopeUrl("scopes/{$region->id}/grants"))
        ->assertOk()
        ->assertJsonPath('grants.0.user_id', (string) $member->id)
        ->assertJsonPath('grants.0.user_name', $member->name)
        ->assertJsonPath('grants.0.capacity', 'reviewer')
        ->assertJsonPath('grants.0.includes_descendants', true);
});
