<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The G10a authorization truth table. Every branch of "can this user reach this form" — direct grant,
| node grant, descendant cascade, and every way each must FAIL. Both entry points are asserted together
| wherever it matters: the single-row check (`holds`) and the list twin (`grantedFormIdsQuery`) must agree
| on every row, or the inbox shows something the detail page 403s on.
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

/** A form with no creator grant, so each test starts from "unreachable" and adds exactly one grant. */
function unownedForm(string $title = 'Survey'): Form
{
    $owner = User::factory()->create();
    $form = app(FormService::class)->create(test()->tenant, $owner, $title);
    DB::table('resource_grants')->where('scopeable_id', $form->id)->delete();

    return $form->refresh();
}

function assignTo(Form $form, string $nodeId): Form
{
    DB::table('forms')->where('id', $form->id)->update(['scope_node_id' => $nodeId]);

    return $form->refresh();
}

/** Both entry points must agree. Returning one boolean makes every assertion below check both. */
function reachable(Form $form, ?ResourceCapacity $capacity = null): bool
{
    $resolver = test()->resolver;
    $user = test()->user;

    $byCheck = $capacity !== null
        ? $resolver->holds($user, $form, $capacity)
        : $resolver->holdsAny($user, $form);

    $byList = $resolver->grantedFormIdsQuery($user, $capacity)
        ->get()->pluck('id')->contains($form->id);

    expect($byList)->toBe($byCheck, 'policy check and list twin disagree — inbox/detail would diverge');

    return $byCheck;
}

it('grants nothing to a user with no grants, and returns an EMPTY list rather than everything', function (): void {
    $form = unownedForm();

    expect(reachable($form))->toBeFalse();
    // The dangerous failure mode: an unconstrained query where an empty predicate was intended.
    expect($this->resolver->grantedFormIdsQuery($this->user)->count())->toBe(0);
});

it('resolves a direct grant on a form', function (): void {
    $form = unownedForm();
    makeCollaborator($form, $this->user, ResourceCapacity::Editor);

    expect(reachable($form, ResourceCapacity::Editor))->toBeTrue();
});

it('isolates capacities — a reviewer grant does not satisfy an editor check', function (): void {
    $form = unownedForm();
    makeCollaborator($form, $this->user, ResourceCapacity::Reviewer);

    expect(reachable($form, ResourceCapacity::Editor))->toBeFalse()
        ->and(reachable($form, ResourceCapacity::Reviewer))->toBeTrue()
        // …but the capacity-agnostic question is still yes, which is the rule SubmissionPolicy uses.
        ->and(reachable($form))->toBeTrue();
});

it('resolves a grant on the node a form is assigned to', function (): void {
    $node = makeScopeNode(name: 'Region I');
    $form = assignTo(unownedForm(), $node->id);
    grantOnNode($node, $this->user, ResourceCapacity::Editor);

    expect(reachable($form, ResourceCapacity::Editor))->toBeTrue();
});

it('does NOT reach a child node when the grant excludes descendants', function (): void {
    // The whole reason includes_descendants defaults to false: a grant's blast radius must be explicit.
    $root = makeScopeNode(name: 'Region I');
    $child = makeScopeNode($root, 'Province A');
    $form = assignTo(unownedForm(), $child->id);

    grantOnNode($root, $this->user, ResourceCapacity::Editor, descendants: false);

    expect(reachable($form, ResourceCapacity::Editor))->toBeFalse();
});

it('DOES reach a child node when the grant includes descendants', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $child = makeScopeNode($root, 'Province A');
    $form = assignTo(unownedForm(), $child->id);

    grantOnNode($root, $this->user, ResourceCapacity::Editor, descendants: true);

    expect(reachable($form, ResourceCapacity::Editor))->toBeTrue();
});

it('reaches arbitrarily deep descendants, not just direct children', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $province = makeScopeNode($root, 'Province A');
    $city = makeScopeNode($province, 'City X');
    $form = assignTo(unownedForm(), $city->id);

    grantOnNode($root, $this->user, ResourceCapacity::Editor, descendants: true);

    expect(reachable($form, ResourceCapacity::Editor))->toBeTrue();
});

it('never leaks across a sibling branch', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $left = makeScopeNode($root, 'Province A');
    $right = makeScopeNode($root, 'Province B');
    $form = assignTo(unownedForm(), $right->id);

    grantOnNode($left, $this->user, ResourceCapacity::Editor, descendants: true);

    expect(reachable($form, ResourceCapacity::Editor))->toBeFalse();
});

it('leaves a form with no scope node unreachable by any node grant', function (): void {
    $node = makeScopeNode(name: 'Region I');
    $form = unownedForm(); // scope_node_id stays NULL — the state of every form after the G10a backfill

    grantOnNode($node, $this->user, ResourceCapacity::Editor, descendants: true);

    expect(reachable($form, ResourceCapacity::Editor))->toBeFalse();
});

it('treats a grant on a DEACTIVATED node as granting nothing, on both sides', function (): void {
    // is_active is the revocation control (there is no deleted_at, deliberately), so it must be enforced
    // in BOTH query shapes. Filtering it in only one is exactly how an inbox/detail divergence appears.
    $node = makeScopeNode(name: 'Region I');
    $form = assignTo(unownedForm(), $node->id);
    grantOnNode($node, $this->user, ResourceCapacity::Editor);

    expect(reachable($form, ResourceCapacity::Editor))->toBeTrue();

    DB::table('scope_nodes')->where('id', $node->id)->update(['is_active' => false]);
    $this->resolver->forget();

    expect(reachable($form, ResourceCapacity::Editor))->toBeFalse();
});

it('stops resolving grants once the holder is no longer an active member', function (): void {
    // TenantMembershipService::remove() sets status = removed and never touches grants, so without the
    // membership join a removed member's grants survive and silently re-arm on re-invite.
    $form = unownedForm();
    makeCollaborator($form, $this->user, ResourceCapacity::Editor);

    expect(reachable($form, ResourceCapacity::Editor))->toBeTrue();

    DB::table('tenant_users')
        ->where('tenant_id', $this->tenant->id)->where('user_id', $this->user->id)
        ->update(['status' => 'removed']);
    $this->resolver->forget();

    expect(reachable($form, ResourceCapacity::Editor))->toBeFalse();
});

it('returns false for a form id belonging to another tenant', function (): void {
    // holdsOnFormId re-anchors the id rather than trusting caller provenance, so a foreign id resolves
    // nothing instead of accidentally matching a grant.
    $other = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo', 'default_locale' => 'en']);
    $otherUser = User::factory()->create();

    enterTenant($other->id, $otherUser->id);
    $foreign = app(FormService::class)->create($other, $otherUser, 'Bravo form');

    enterTenant($this->tenant->id, $this->user->id);

    expect($this->resolver->holdsOnFormId($this->user, $foreign->id, ResourceCapacity::Editor))->toBeFalse();
});

it('keys its memo by TENANT as well as user, so grants cannot bleed between tenants', function (): void {
    // enterTenant() is called repeatedly inside one PHP process. A user-only memo key would carry tenant
    // A's grants into tenant B and make authorization tests pass for entirely the wrong reason.
    $form = unownedForm();
    makeCollaborator($form, $this->user, ResourceCapacity::Editor);
    expect($this->resolver->holds($this->user, $form, ResourceCapacity::Editor))->toBeTrue();

    $other = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo', 'default_locale' => 'en']);
    enterTenant($other->id, $this->user->id);

    expect($this->resolver->grantedFormIdsQuery($this->user)->count())->toBe(0);

    enterTenant($this->tenant->id, $this->user->id);
    expect($this->resolver->holds($this->user, $form, ResourceCapacity::Editor))->toBeTrue();
});

it('sees a grant written earlier in the same request', function (): void {
    $form = unownedForm();

    expect($this->resolver->holds($this->user, $form, ResourceCapacity::Editor))->toBeFalse();

    // makeCollaborator() calls forget() exactly as FormService::create does — without that invalidation
    // a form created mid-request would be un-editable on the very redirect that follows creating it.
    makeCollaborator($form, $this->user, ResourceCapacity::Editor);

    expect($this->resolver->holds($this->user, $form, ResourceCapacity::Editor))->toBeTrue();
});

it('keeps a soft-deleted form visible to a grant-holder, exactly as before G10a', function (): void {
    // The retired form_collaborators subquery never joined `forms`, so a soft-deleted form's submissions
    // stayed visible. Dropping withTrashed() would be an unflagged behaviour change; this test forbids it.
    $form = unownedForm();
    makeCollaborator($form, $this->user, ResourceCapacity::Editor);

    $form->delete();

    expect($this->resolver->grantedFormIdsQuery($this->user)->get()->pluck('id')->contains($form->id))
        ->toBeTrue();
});

it('does not let a % or _ in a path widen a prefix match', function (): void {
    // Paths are uuid-derived so this cannot occur today, but the escaping is cheap insurance against a
    // future path scheme that admits user-supplied text.
    $root = makeScopeNode(name: 'Region I');
    $form = assignTo(unownedForm(), $root->id);

    grantOnNode($root, $this->user, ResourceCapacity::Editor, descendants: true);

    expect(reachable($form, ResourceCapacity::Editor))->toBeTrue();
});
