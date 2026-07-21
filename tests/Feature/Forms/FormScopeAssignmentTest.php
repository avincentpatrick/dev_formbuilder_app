<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Models\Form;
use App\Models\ScopeNode;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Forms\FormService;
use App\Services\Scoping\ScopeNodeService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment G10b2 — assigning a form to the scoping hierarchy.
|--------------------------------------------------------------------------
| Assigning a form to a node is a GRANT-EQUIVALENT act, not a metadata edit. Writing forms.scope_node_id
| confers capacity on the form — and, through SubmissionPolicy::collaboratesWith, on its entire submission
| history — to every holder of a grant on that node and on any ancestor whose grant sets
| includes_descendants. Clearing it strips every node-derived reviewer while leaving direct grants intact.
|
| `can:update,form` alone is NOT a sufficient gate: FormPolicy::canEdit composes forms.edit.own with the
| editor grant FormService::create mints the creator, so any form_editor holds it on any form they made.
| The route therefore stacks can:viewAny,ScopeNode (scopes.manage) on top. These tests pin both halves,
| plus the $fillable trap that makes the explicit writer necessary.
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

function formScopeUrl(Form $form): string
{
    return "http://acme.meridian.test/forms/{$form->id}/scope";
}

it('lets an admin assign a form to a node', function (): void {
    $node = makeScopeNode(null, 'Region I');
    $form = makeForm($this->admin, 'Household Survey');

    $this->actingAs($this->admin)->withoutVite()
        ->patch(formScopeUrl($form), ['scope_node_id' => $node->id])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::query()->whereKey($form->id)->firstOrFail()->scope_node_id)->toBe($node->id);
});

it('lets an admin un-assign a form', function (): void {
    $node = makeScopeNode(null, 'Region I');
    $form = formIn($node, 'Household Survey');

    $this->actingAs($this->admin)->withoutVite()
        ->patch(formScopeUrl($form), ['scope_node_id' => null])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::query()->whereKey($form->id)->firstOrFail()->scope_node_id)->toBeNull();
});

it('refuses a form editor who can update the form but does not hold scopes.manage', function (): void {
    $node = makeScopeNode(null, 'Region I');

    $editor = User::factory()->create();
    enterTenant($this->tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');

    // Built through FormService::create, not the makeForm() helper: only the real path mints the creator an
    // Editor grant, and this test is precisely about a principal who genuinely DOES pass can:update,form.
    // (FormPolicy::canEdit = forms.edit.any OR forms.edit.own + an editor grant; form_editor holds the own
    // half.) Without the stacked gate they could re-parent their form under a branch and hand its
    // submissions to that branch's reviewers.
    $form = app(FormService::class)->create($this->tenant, $editor, 'Editor Survey');
    expect($editor->can('update', $form))->toBeTrue();

    $this->actingAs($editor)->withoutVite()
        ->patch(formScopeUrl($form), ['scope_node_id' => $node->id])
        ->assertForbidden();

    enterTenant($this->tenant->id, $editor->id);
    expect(Form::query()->whereKey($form->id)->firstOrFail()->scope_node_id)->toBeNull();
});

it('refuses assignment to a deactivated node', function (): void {
    $node = makeScopeNode(null, 'Region I');
    app(ScopeNodeService::class)->setActive($node, false);
    $form = makeForm($this->admin, 'Household Survey');

    // The resolver discards inactive paths, so parking a form on a deactivated node would be a disguised
    // un-assign — refused outright rather than silently accepted.
    $this->actingAs($this->admin)->withoutVite()
        ->patch(formScopeUrl($form), ['scope_node_id' => $node->id])
        ->assertNotFound();
});

it('refuses a node from another tenant', function (): void {
    $form = makeForm($this->admin, 'Household Survey');

    $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
    $otherUser = User::factory()->create();
    enterTenant($other->id, $otherUser->id);
    makeActiveMember($otherUser, 'admin');
    $foreignNode = makeScopeNode(null, 'Foreign Region');

    enterTenant($this->tenant->id, $this->admin->id);

    $this->actingAs($this->admin)->withoutVite()
        ->patch(formScopeUrl($form), ['scope_node_id' => $foreignNode->id])
        // RLS hides it, so `exists:scope_nodes,id` fails validation rather than leaking that the id exists
        // or tripping the composite FK with a 500.
        ->assertStatus(302)
        ->assertSessionHasErrors('scope_node_id');
});

it('cannot set scope_node_id through the form metadata route', function (): void {
    $node = makeScopeNode(null, 'Region I');
    $form = makeForm($this->admin, 'Household Survey');

    $this->actingAs($this->admin)->withoutVite()
        ->patch("http://acme.meridian.test/forms/{$form->id}", [
            'title' => 'Renamed',
            'scope_node_id' => $node->id,
        ])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $fresh = Form::query()->whereKey($form->id)->firstOrFail();
    // scope_node_id IS in Form::$fillable, so this is guarding a live trap, not a hypothetical: a
    // $form->update($request->validated()) anywhere on the can:update,form gate would hand a plain form
    // editor the ability to re-scope. The write is centralized in FormService::assignScope for that reason.
    expect($fresh->title)->toBe('Renamed')
        ->and($fresh->scope_node_id)->toBeNull();
});

it('changes a grant holder reach on re-scope without any resolver invalidation', function (): void {
    $regionA = makeScopeNode(null, 'Region A');
    $regionB = makeScopeNode(null, 'Region B');
    $form = formIn($regionA, 'Household Survey');

    $member = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($member, 'form_editor');
    grantOnNode($regionA, $member, ResourceCapacity::Reviewer);

    $resolver = app(ResourceGrantResolver::class);
    expect($resolver->holds($member, $form->refresh(), ResourceCapacity::Reviewer))->toBeTrue();

    app(FormService::class)->assignScope($form, (string) $regionB->id);

    // Deliberately NO forget() between the two checks. Nothing memoized is keyed by form id — $formPaths is
    // keyed by NODE id and holds() reads scope_node_id live off the model — which is why assignScope does
    // not need the invalidation setActive/move/delete all perform. This test is what pins that.
    expect($resolver->holds($member, $form->refresh(), ResourceCapacity::Reviewer))->toBeFalse();
});
