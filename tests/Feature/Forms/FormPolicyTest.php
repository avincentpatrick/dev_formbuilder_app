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

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run(); // idempotent, committed on the privileged connection
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('lets an Admin edit, publish, and delete any form via the .any permissions', function (): void {
    $creator = User::factory()->create();
    enterTenant($this->tenant->id, $creator->id);
    $form = app(FormService::class)->create($this->tenant, $creator, 'Survey');

    $admin = User::factory()->create();
    enterTenant($this->tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    expect($admin->can('update', $form))->toBeTrue()
        ->and($admin->can('publish', $form))->toBeTrue()
        ->and($admin->can('delete', $form))->toBeTrue();
});

it('confines a Form Editor to the forms they collaborate on (.own)', function (): void {
    $editor = User::factory()->create();
    $other = User::factory()->create();

    // The editor creates a form → gets an editor collaborator row for it.
    enterTenant($this->tenant->id, $editor->id);
    $mine = app(FormService::class)->create($this->tenant, $editor, 'Mine');
    makeActiveMember($editor, 'form_editor');

    // Someone else's form — the editor is not a collaborator on it.
    enterTenant($this->tenant->id, $other->id);
    $theirs = app(FormService::class)->create($this->tenant, $other, 'Theirs');

    enterTenant($this->tenant->id, $editor->id);

    expect($editor->can('update', $mine))->toBeTrue()       // editor collaborator on this one
        ->and($editor->can('publish', $mine))->toBeTrue()
        ->and($editor->can('update', $theirs))->toBeFalse() // no collaborator row → .own fails
        ->and($editor->can('delete', $mine))->toBeFalse();  // editors have no forms.delete at all
});

it('denies a Viewer any edit, publish, or create', function (): void {
    $creator = User::factory()->create();
    enterTenant($this->tenant->id, $creator->id);
    $form = app(FormService::class)->create($this->tenant, $creator, 'Survey');

    $viewer = User::factory()->create();
    enterTenant($this->tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    expect($viewer->can('update', $form))->toBeFalse()
        ->and($viewer->can('publish', $form))->toBeFalse()
        ->and($viewer->can('create', Form::class))->toBeFalse();
});

/*
| Increment G10a — the same `.own` composition, now reachable through the scoping hierarchy. The three
| tests above are unchanged on purpose: they are the behaviour-preservation proof for the direct-grant
| path, which is the only path that exists after the form_collaborators backfill.
*/

it('scopes a Form Editor through a grant on the node their form is assigned to', function (): void {
    $editor = User::factory()->create();
    $other = User::factory()->create();

    enterTenant($this->tenant->id, $other->id);
    $form = app(FormService::class)->create($this->tenant, $other, 'Regional survey');
    $node = makeScopeNode(name: 'Region I');
    DB::table('forms')->where('id', $form->id)->update(['scope_node_id' => $node->id]);

    enterTenant($this->tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    expect($editor->can('update', $form->refresh()))->toBeFalse();

    grantOnNode($node, $editor, ResourceCapacity::Editor);

    expect($editor->can('update', $form->refresh()))->toBeTrue()
        ->and($editor->can('publish', $form->refresh()))->toBeTrue()
        // Still no tenant-wide delete: the node grant scopes `.own`, it does not confer `.any`.
        ->and($editor->can('delete', $form->refresh()))->toBeFalse();
});

it('reaches a descendant node only when the grant says so', function (): void {
    $editor = User::factory()->create();
    $owner = User::factory()->create();

    enterTenant($this->tenant->id, $owner->id);
    $root = makeScopeNode(name: 'Region I');
    $child = makeScopeNode($root, 'Province A');
    $form = app(FormService::class)->create($this->tenant, $owner, 'City survey');
    DB::table('forms')->where('id', $form->id)->update(['scope_node_id' => $child->id]);

    enterTenant($this->tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');

    $grant = grantOnNode($root, $editor, ResourceCapacity::Editor, descendants: false);
    expect($editor->can('update', $form->refresh()))->toBeFalse();

    $grant->includes_descendants = true;
    $grant->save();
    app(ResourceGrantResolver::class)->forget($editor->id);

    expect($editor->can('update', $form->refresh()))->toBeTrue();
});

it('does not let a reviewer-capacity node grant confer edit rights', function (): void {
    $reviewer = User::factory()->create();
    $owner = User::factory()->create();

    enterTenant($this->tenant->id, $owner->id);
    $node = makeScopeNode(name: 'Region I');
    $form = app(FormService::class)->create($this->tenant, $owner, 'Regional survey');
    DB::table('forms')->where('id', $form->id)->update(['scope_node_id' => $node->id]);

    enterTenant($this->tenant->id, $reviewer->id);
    makeActiveMember($reviewer, 'form_editor');
    grantOnNode($node, $reviewer, ResourceCapacity::Reviewer);

    expect($reviewer->can('update', $form->refresh()))->toBeFalse()
        ->and($reviewer->can('publish', $form->refresh()))->toBeFalse();
});
